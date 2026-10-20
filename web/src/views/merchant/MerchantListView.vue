<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { usePagedList } from '@/utils/paged'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Plus, WarningFilled } from '@element-plus/icons-vue'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { type AvailableProduct, type Merchant, merchantApi, type MerchantProduct, type MerchantUser } from '@/api/merchant'
import type { OperatorCode } from '@/api/product'
import { alipayAccountApi, type MerchantAlipayAccount, type ReceivingAccount, receivingAccountApi } from '@/api/funding'
import { labelOf, operatorLabels, supplierStatusLabels, toOptions } from '@/utils/labels'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('merchant.manage'))
const canPrice = computed(() => permission.can('merchant.price'))
const canBalance = computed(() => permission.can('merchant.balance'))
const canFundingConfig = computed(() => permission.can('funding.config'))

const list = usePagedList<Merchant, { keyword: string; status: string }>(merchantApi.list, { keyword: '', status: '' })
const { rows, total, page, perPage, loading, filters } = list

// ---- 收款配置：这个商户能看到、能选哪几个打款账户 ----
const receivingVisible = ref(false)
const receivingMerchant = ref<Merchant | null>(null)
const receivingAccounts = ref<ReceivingAccount[]>([])
const receivingIds = ref<number[]>([])
const receivingSaving = ref(false)
// 支付宝转账加款：一个商户指定一个账户，空表示不能用
const alipayAccounts = ref<MerchantAlipayAccount['accounts']>([])
const alipayAccountId = ref<number | null>(null)

async function openReceiving(row: Merchant) {
  receivingMerchant.value = row
  const [accounts, alipay] = await Promise.all([receivingAccountApi.forMerchant(row.id), alipayAccountApi.forMerchant(row.id)])
  receivingAccounts.value = accounts
  receivingIds.value = accounts.filter((a) => a.assigned).map((a) => a.id)
  alipayAccounts.value = alipay.accounts
  alipayAccountId.value = alipay.alipay_account_id
  receivingVisible.value = true
}

async function saveReceiving() {
  receivingSaving.value = true
  try {
    await Promise.all([
      receivingAccountApi.assignToMerchant(receivingMerchant.value!.id, receivingIds.value),
      alipayAccountApi.bindMerchant(receivingMerchant.value!.id, alipayAccountId.value ?? null),
    ])
    ElMessage.success('已保存')
    receivingVisible.value = false
  } finally {
    receivingSaving.value = false
  }
}

// ---- 新建 / 编辑 ----
const dialogVisible = ref(false)
const editing = ref<Merchant | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ name: '', contact: '', phone: '', notify_url: '', ip_whitelist: '', remark: '' })
const rules: FormRules = {
  name: [{ required: true, message: '请输入商户名称', trigger: 'blur' }],
  notify_url: [{ pattern: /^https?:\/\/.+/i, message: '以 http:// 或 https:// 开头', trigger: 'blur' }],
}

function openCreate() {
  editing.value = null
  Object.assign(form, { name: '', contact: '', phone: '', notify_url: '', ip_whitelist: '', remark: '' })
  dialogVisible.value = true
}

function openEdit(row: Merchant) {
  editing.value = row
  Object.assign(form, {
    name: row.name,
    contact: row.contact ?? '',
    phone: row.phone ?? '',
    notify_url: row.notify_url ?? '',
    ip_whitelist: row.ip_whitelist.join('\n'),
    remark: row.remark ?? '',
  })
  dialogVisible.value = true
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  saving.value = true
  try {
    if (editing.value) {
      await merchantApi.update(editing.value.id, { ...form })
      ElMessage.success('已保存')
    } else {
      const created = await merchantApi.create({ ...form })
      showSecret(created.name, created.app_key, created.app_secret)
    }
    dialogVisible.value = false
    await list.load()
  } finally {
    saving.value = false
  }
}

// ---- 密钥：只显示一次 ----
const secretVisible = ref(false)
const secret = reactive({ name: '', app_key: '', app_secret: '' })

function showSecret(name: string, appKey: string, appSecret: string) {
  Object.assign(secret, { name, app_key: appKey, app_secret: appSecret })
  secretVisible.value = true
}

async function copySecret() {
  try {
    await navigator.clipboard.writeText(`AppKey: ${secret.app_key}\nAppSecret: ${secret.app_secret}`)
    ElMessage.success('已复制')
  } catch {
    ElMessage.warning('复制失败，请手动选中复制')
  }
}

async function resetSecret(row: Merchant) {
  try {
    await ElMessageBox.confirm(`重置后「${row.name}」的旧密钥立即失效，对方要换成新密钥才能继续下单，确定重置吗？`, '重置密钥', {
      type: 'warning',
    })
  } catch {
    return
  }
  const result = await merchantApi.resetSecret(row.id)
  showSecret(row.name, result.app_key, result.app_secret)
}

async function toggleStatus(row: Merchant) {
  const next = row.status === 'active' ? 'disabled' : 'active'
  try {
    await ElMessageBox.confirm(
      next === 'disabled' ? `停用后「${row.name}」不能再下单，确定停用吗？` : `确定启用「${row.name}」吗？`,
      next === 'disabled' ? '停用商户' : '启用商户',
      { type: 'warning' },
    )
  } catch {
    return
  }
  await merchantApi.changeStatus(row.id, next)
  ElMessage.success(next === 'disabled' ? '已停用' : '已启用')
  await list.load()
}

// ---- 加款 / 扣款 ----
const balanceVisible = ref(false)
const balanceTarget = ref<Merchant | null>(null)
const balanceSaving = ref(false)
const balanceForm = reactive({ type: 'recharge' as 'recharge' | 'deduct', amount: '', remark: '' })

function openBalance(row: Merchant) {
  balanceTarget.value = row
  Object.assign(balanceForm, { type: 'recharge', amount: '', remark: '' })
  balanceVisible.value = true
}

async function saveBalance() {
  if (!/^\d{1,9}(\.\d{1,2})?$/.test(balanceForm.amount) || Number(balanceForm.amount) <= 0) {
    ElMessage.error('请输入大于 0 的金额，最多两位小数')
    return
  }
  if (balanceForm.remark.trim() === '') {
    ElMessage.error('请填写备注，例如打款凭证号')
    return
  }
  balanceSaving.value = true
  try {
    const result = await merchantApi.adjustBalance(balanceTarget.value!.id, { ...balanceForm })
    ElMessage.success(`已${balanceForm.type === 'recharge' ? '加款' : '扣款'}，当前余额 ${result.balance} 元`)
    balanceVisible.value = false
    await list.load()
  } finally {
    balanceSaving.value = false
  }
}

// ---- 商品与价格 ----
const productsVisible = ref(false)
const productsTarget = ref<Merchant | null>(null)
const productsLoading = ref(false)
/**
 * 平台商品全列出来：已开通 / 已关闭的是商户开通记录，还没开通的（只有上架的平台商品）status 为 none，
 * 价格先按默认售价带出，改好后点「开通」。draft：正在编辑的价格，点保存或开通才提交。
 */
type DraftRow = MerchantProduct & { draft: Partial<Record<OperatorCode, string>> }
const productRows = ref<DraftRow[]>([])

/** 按运营商分 tab（商品覆盖这个运营商就算），只在前端筛选、分页 */
type OperatorTab = 'all' | OperatorCode
const operatorTabs: { name: OperatorTab; label: string }[] = [
  { name: 'all', label: '全部' },
  { name: 'cmcc', label: '移动' },
  { name: 'ctcc', label: '电信' },
  { name: 'cucc', label: '联通' },
  { name: 'cbn', label: '广电' },
]
/** 按开通状态筛：active 已开通 / disabled 已关闭 / none 未开通 */
type OpenFilter = 'all' | 'active' | 'disabled' | 'none'
const productTab = ref<OperatorTab>('all')
const productKeyword = ref('')
const productOpenFilter = ref<OpenFilter>('all')
const productPage = ref(1)
const productPageSize = ref(20)

function coversTab(operators: OperatorCode[], tab: OperatorTab): boolean {
  return tab === 'all' || operators.includes(tab)
}

function matchesKeyword(name: string | null, code: string | null): boolean {
  const keyword = productKeyword.value.trim().toLowerCase()
  return keyword === '' || (name ?? '').toLowerCase().includes(keyword) || (code ?? '').toLowerCase().includes(keyword)
}

function matchesOpenFilter(row: DraftRow): boolean {
  return productOpenFilter.value === 'all' || row.status === productOpenFilter.value
}

function tabCount(tab: OperatorTab): number {
  return productRows.value.filter((p) => coversTab(p.operators, tab)).length
}

const filteredProducts = computed(() =>
  productRows.value.filter(
    (p) => coversTab(p.operators, productTab.value) && matchesKeyword(p.product_name, p.product_code) && matchesOpenFilter(p),
  ),
)
const pagedProducts = computed(() =>
  filteredProducts.value.slice((productPage.value - 1) * productPageSize.value, productPage.value * productPageSize.value),
)
const openedCount = computed(() => productRows.value.filter((p) => p.status === 'active').length)

/** 选了某个运营商 tab 时只显示这个运营商的价格 */
function visibleOperators(row: DraftRow): OperatorCode[] {
  return productTab.value === 'all' ? row.operators : row.operators.filter((op) => op === productTab.value)
}

watch([productTab, productKeyword, productOpenFilter, productPageSize], () => {
  productPage.value = 1
})

// 保存后重新加载，当前页可能没数据了（比如最后一页）
watch(filteredProducts, (rows) => {
  const lastPage = Math.max(1, Math.ceil(rows.length / productPageSize.value))
  if (productPage.value > lastPage) {
    productPage.value = lastPage
  }
})

/** 还没开通的平台商品，排成和开通记录一样的结构 */
function unopenedRow(merchantId: number, p: AvailableProduct): DraftRow {
  return {
    id: 0,
    merchant_id: merchantId,
    product_id: p.id,
    product_code: p.code,
    product_name: p.name,
    product_status: 'active',
    face_value: p.face_value,
    operators: p.operators,
    default_prices: p.prices,
    prices: {},
    status: 'none',
    warnings: [],
    updated_at: null,
    draft: { ...p.prices },
  }
}

async function loadProducts() {
  const id = productsTarget.value!.id
  productsLoading.value = true
  try {
    const [opened, rest] = await Promise.all([merchantApi.products(id), merchantApi.availableProducts(id)])
    productRows.value = [
      ...opened.map((p) => ({ ...p, draft: { ...p.prices } })),
      ...rest.map((p) => unopenedRow(id, p)),
    ].sort((a, b) => (a.face_value ?? 0) - (b.face_value ?? 0) || a.product_id - b.product_id)
  } finally {
    productsLoading.value = false
  }
}

function openProducts(row: Merchant) {
  productsTarget.value = row
  productRows.value = []
  productTab.value = 'all'
  productKeyword.value = ''
  productOpenFilter.value = 'all'
  productPage.value = 1
  productsVisible.value = true
  loadProducts()
}

/** 保存价格；传 status 时同时开通 / 关闭（没开通过的商品开通时按填的价格建开通记录） */
async function saveProduct(row: DraftRow, status?: string) {
  for (const op of row.operators) {
    const value = row.draft[op]?.trim()
    if (value && !/^\d{1,9}(\.\d{1,2})?$/.test(value)) {
      ElMessage.error(`${labelOf(operatorLabels, op)}价格格式不对，最多两位小数`)
      return
    }
  }
  const saved = await merchantApi.saveProduct(row.merchant_id, row.product_id, { status: status ?? row.status, prices: row.draft })
  const action = row.status === 'none' ? `已开通「${row.product_name}」` : '已保存'
  ElMessage.success(saved.warnings.length > 0 ? `${action}，有 ${saved.warnings.length} 条提醒` : action)
  await loadProducts()
  await list.load()
}

// ---- 商户后台账号 ----
const usersVisible = ref(false)
const usersTarget = ref<Merchant | null>(null)
const users = ref<MerchantUser[]>([])
const usersLoading = ref(false)
const userForm = reactive({ username: '', password: '', real_name: '' })

async function loadUsers() {
  usersLoading.value = true
  try {
    users.value = await merchantApi.users(usersTarget.value!.id)
  } finally {
    usersLoading.value = false
  }
}

function openUsers(row: Merchant) {
  usersTarget.value = row
  users.value = []
  Object.assign(userForm, { username: '', password: '', real_name: '' })
  usersVisible.value = true
  loadUsers()
}

async function createUser() {
  if (!/^[A-Za-z0-9_.@-]{3,64}$/.test(userForm.username)) {
    ElMessage.error('账号为 3~64 位字母、数字或 _ . @ -')
    return
  }
  if (userForm.password === '') {
    ElMessage.error('请设置初始密码')
    return
  }
  await merchantApi.createUser(usersTarget.value!.id, { ...userForm })
  ElMessage.success('已开通，请把账号和初始密码告诉商户，并提醒对方登录后修改密码')
  Object.assign(userForm, { username: '', password: '', real_name: '' })
  await loadUsers()
}

async function toggleUser(user: MerchantUser) {
  const next = user.status === 'active' ? 'disabled' : 'active'
  await merchantApi.changeUserStatus(user.merchant_id, user.id, next)
  ElMessage.success(next === 'disabled' ? '已禁用，对方会立即退出登录' : '已启用')
  await loadUsers()
}

async function resetUserPassword(user: MerchantUser) {
  let password: string
  try {
    const result = await ElMessageBox.prompt(`给「${user.username}」设置新密码，对方之前的登录会失效`, '重置密码', {
      inputType: 'password',
      inputValidator: (v) => !!v || '请输入新密码',
    })
    password = result.value
  } catch {
    return
  }
  await merchantApi.resetUserPassword(user.merchant_id, user.id, password)
  ElMessage.success('密码已重置，请把新密码告诉对方')
}

onMounted(async () => {
  await permission.load()
  list.load()
})
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <el-form inline @submit.prevent="list.search">
        <el-form-item>
          <el-input v-model="filters.keyword" placeholder="名称 / AppKey" clearable style="width: 200px" />
        </el-form-item>
        <el-form-item>
          <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 110px">
            <el-option v-for="o in toOptions(supplierStatusLabels)" :key="o.value" v-bind="o" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit">查询</el-button>
        </el-form-item>
      </el-form>
      <el-button v-if="canManage" type="primary" :icon="Plus" @click="openCreate">新建商户</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="名称" min-width="140" />
      <el-table-column label="联系人" min-width="120">
        <template #default="{ row }">{{ [row.contact, row.phone].filter(Boolean).join(' ') || '-' }}</template>
      </el-table-column>
      <el-table-column prop="app_key" label="AppKey" min-width="270" />
      <el-table-column label="余额（元）" width="120" align="right">
        <template #default="{ row }">{{ row.balance }}</template>
      </el-table-column>
      <el-table-column prop="product_count" label="已开通商品" width="100" align="center" />
      <el-table-column label="IP 白名单" min-width="140">
        <template #default="{ row }">
          <span v-if="row.ip_whitelist.length === 0" class="warn-text">
            <el-icon><WarningFilled /></el-icon> 不限制
          </span>
          <span v-else>{{ row.ip_whitelist.join('、') }}</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }"><StatusTag :map="supplierStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="操作" width="370" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openProducts(row as Merchant)">商品与价格</el-button>
          <el-button link type="primary" @click="openUsers(row as Merchant)">后台账号</el-button>
          <el-button v-if="canBalance" link type="primary" @click="openBalance(row as Merchant)">加/扣款</el-button>
          <el-button v-if="canFundingConfig" link type="primary" @click="openReceiving(row as Merchant)">收款配置</el-button>
          <template v-if="canManage">
            <el-button link type="primary" @click="openEdit(row as Merchant)">编辑</el-button>
            <el-button link type="warning" @click="resetSecret(row as Merchant)">重置密钥</el-button>
            <el-button link :type="row.status === 'active' ? 'danger' : 'success'" @click="toggleStatus(row as Merchant)">
              {{ row.status === 'active' ? '停用' : '启用' }}
            </el-button>
          </template>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="page"
      v-model:page-size="perPage"
      class="pagination"
      layout="total, sizes, prev, pager, next"
      :total="total"
      @current-change="list.load"
      @size-change="list.search"
    />
  </el-card>

  <!-- 新建 / 编辑 -->
  <el-dialog v-model="dialogVisible" :title="editing ? '编辑商户' : '新建商户'" width="560px" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="96px">
      <el-form-item label="名称" prop="name">
        <el-input v-model="form.name" maxlength="64" />
      </el-form-item>
      <el-form-item label="联系人">
        <el-input v-model="form.contact" maxlength="32" style="width: 160px" />
        <el-input v-model="form.phone" maxlength="32" placeholder="电话" style="width: 200px; margin-left: 8px" />
      </el-form-item>
      <el-form-item label="通知地址" prop="notify_url">
        <el-input v-model="form.notify_url" placeholder="订单出结果后推送到这里，商户下单时也可以单独传" />
      </el-form-item>
      <el-form-item label="IP 白名单">
        <el-input
          v-model="form.ip_whitelist"
          type="textarea"
          :rows="3"
          placeholder="每行一个 IP 或网段（如 1.2.3.4、10.0.0.0/24）；留空表示不限制，不建议"
        />
      </el-form-item>
      <el-form-item label="备注">
        <el-input v-model="form.remark" maxlength="255" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>

  <!-- 密钥只显示一次 -->
  <el-dialog v-model="secretVisible" title="接口密钥" width="min(760px, 92vw)" :close-on-click-modal="false">
    <el-alert type="warning" :closable="false" show-icon title="密钥只显示这一次，关闭后无法再查看，请立即复制并安全地交给商户" />
    <el-descriptions :column="1" border class="secret">
      <el-descriptions-item label="商户">{{ secret.name }}</el-descriptions-item>
      <el-descriptions-item label="AppKey"><code>{{ secret.app_key }}</code></el-descriptions-item>
      <el-descriptions-item label="AppSecret"><code>{{ secret.app_secret }}</code></el-descriptions-item>
    </el-descriptions>
    <template #footer>
      <el-button @click="copySecret">复制</el-button>
      <el-button type="primary" @click="secretVisible = false">我已保存</el-button>
    </template>
  </el-dialog>

  <!-- 加款 / 扣款 -->
  <el-dialog v-model="balanceVisible" title="加款 / 扣款" width="440px">
    <el-form label-width="80px">
      <el-form-item label="商户">{{ balanceTarget?.name }}（当前余额 {{ balanceTarget?.balance }} 元）</el-form-item>
      <el-form-item label="类型">
        <el-radio-group v-model="balanceForm.type">
          <el-radio value="recharge">加款</el-radio>
          <el-radio value="deduct">扣款</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="金额（元）" required>
        <el-input v-model="balanceForm.amount" style="width: 160px" />
      </el-form-item>
      <el-form-item label="备注" required>
        <el-input v-model="balanceForm.remark" maxlength="255" placeholder="如打款凭证号、原因" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="balanceVisible = false">取消</el-button>
      <el-button type="primary" :loading="balanceSaving" @click="saveBalance">确定</el-button>
    </template>
  </el-dialog>

  <!-- 商品与价格 -->
  <el-drawer v-model="productsVisible" :title="`商品与价格 - ${productsTarget?.name ?? ''}`" size="80%">
    <el-tabs v-model="productTab" class="product-tabs">
      <el-tab-pane v-for="t in operatorTabs" :key="t.name" :name="t.name" :label="`${t.label} (${tabCount(t.name)})`" />
    </el-tabs>
    <div class="product-toolbar">
      <el-input v-model="productKeyword" placeholder="按商品名称 / 编码筛选" clearable style="width: 260px" />
      <el-radio-group v-model="productOpenFilter">
        <el-radio-button value="all">全部</el-radio-button>
        <el-radio-button value="active">已开通</el-radio-button>
        <el-radio-button value="disabled">已关闭</el-radio-button>
        <el-radio-button value="none">未开通</el-radio-button>
      </el-radio-group>
      <span class="muted">共 {{ productRows.length }} 个平台商品，已开通 {{ openedCount }} 个；未开通的按默认售价带出价格，核对后点「开通」</span>
    </div>
    <el-table
      v-loading="productsLoading"
      :data="pagedProducts"
      border
      :empty-text="productRows.length === 0 ? '还没有上架的平台商品' : '没有符合条件的商品'"
    >
      <el-table-column label="商品" min-width="160">
        <template #default="{ row }">
          <div>{{ row.product_name }}</div>
          <div class="muted">{{ row.product_code }} · {{ row.face_value }} 元</div>
          <el-tooltip v-if="row.warnings.length > 0" placement="top">
            <template #content>
              <div v-for="w in row.warnings" :key="w">{{ w }}</div>
            </template>
            <span class="warn-text"><el-icon><WarningFilled /></el-icon> {{ row.warnings.length }} 条提醒</span>
          </el-tooltip>
        </template>
      </el-table-column>
      <el-table-column label="各运营商价格（元）" min-width="340">
        <template #default="{ row }">
          <div v-if="row.operators.length === 0" class="muted">商品还没有绑定供应商商品</div>
          <div v-for="op in visibleOperators(row as DraftRow)" :key="op" class="price-row">
            <span class="price-label">{{ labelOf(operatorLabels, op) }}</span>
            <el-input v-model="row.draft[op]" :disabled="!canPrice" placeholder="未设置" style="width: 110px" size="small" />
            <span class="muted">默认 {{ row.default_prices[op] ?? '-' }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag v-if="row.status === 'active'" type="success" size="small">已开通</el-tag>
          <el-tag v-else-if="row.status === 'disabled'" type="info" size="small">已关闭</el-tag>
          <el-tag v-else type="warning" effect="plain" size="small">未开通</el-tag>
        </template>
      </el-table-column>
      <el-table-column v-if="canPrice" label="操作" width="120">
        <template #default="{ row }">
          <el-button v-if="row.status === 'none'" link type="primary" @click="saveProduct(row as DraftRow, 'active')">开通</el-button>
          <template v-else>
            <el-button link type="primary" @click="saveProduct(row as DraftRow)">保存价格</el-button>
            <el-button v-if="row.status === 'active'" link type="danger" @click="saveProduct(row as DraftRow, 'disabled')">关闭</el-button>
            <el-button v-else link type="success" @click="saveProduct(row as DraftRow, 'active')">开通</el-button>
          </template>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-model:current-page="productPage"
      v-model:page-size="productPageSize"
      class="pagination"
      layout="total, sizes, prev, pager, next"
      :page-sizes="[20, 50, 100]"
      :total="filteredProducts.length"
    />
  </el-drawer>

  <!-- 商户后台账号 -->
  <el-drawer v-model="usersVisible" :title="`商户后台账号 - ${usersTarget?.name ?? ''}`" size="720px">
    <div class="muted users-tip">商户用这些账号登录商户后台，只能查看自己的订单、资金流水和接入信息。</div>
    <el-form v-if="canManage" inline class="user-form" @submit.prevent="createUser">
      <el-form-item>
        <el-input v-model="userForm.username" placeholder="登录账号" style="width: 160px" />
      </el-form-item>
      <el-form-item>
        <el-input v-model="userForm.password" type="password" show-password placeholder="初始密码" autocomplete="new-password" style="width: 160px" />
      </el-form-item>
      <el-form-item>
        <el-input v-model="userForm.real_name" placeholder="使用人（选填）" style="width: 130px" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">开通账号</el-button>
      </el-form-item>
    </el-form>
    <el-table v-loading="usersLoading" :data="users" border empty-text="还没有账号">
      <el-table-column prop="username" label="账号" min-width="140" />
      <el-table-column label="使用人" width="110">
        <template #default="{ row }">{{ row.real_name ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }"><StatusTag :map="supplierStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="最近登录" width="170">
        <template #default="{ row }">{{ row.last_login_at ?? '-' }}</template>
      </el-table-column>
      <el-table-column v-if="canManage" label="操作" width="140">
        <template #default="{ row }">
          <el-button link type="primary" @click="resetUserPassword(row as MerchantUser)">重置密码</el-button>
          <el-button link :type="row.status === 'active' ? 'danger' : 'success'" @click="toggleUser(row as MerchantUser)">
            {{ row.status === 'active' ? '禁用' : '启用' }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-drawer>

  <el-dialog v-model="receivingVisible" :title="`收款配置 · ${receivingMerchant?.name ?? ''}`" width="1220px">
    <h4 class="receiving-title">线下打款账户</h4>
    <div class="receiving-tip">勾选这个商户能看到的打款账户，商户提交加款申请时只能选这些；一个都不勾，商户就不能申请加款。</div>
    <el-empty v-if="receivingAccounts.length === 0" description="还没有收款账户，先到「商户中心 - 收款配置」新建" />
    <el-checkbox-group v-else v-model="receivingIds" class="receiving-list">
      <el-checkbox v-for="a in receivingAccounts" :key="a.id" :value="a.id" class="receiving-item">
        {{ a.type_label }} · {{ a.account_name }} · {{ a.account_no }}<template v-if="a.bank_name"> · {{ a.bank_name }}</template>
        <el-tag v-if="a.status !== 'active'" size="small" type="info" class="receiving-tag">已停用，商户看不到</el-tag>
      </el-checkbox>
    </el-checkbox-group>

    <h4 class="receiving-title">支付宝转账加款账户</h4>
    <div class="receiving-tip">商户扫码转账到这个账户、自动到账；不选就看不到「支付宝转账」入口。账户在「系统管理 - 系统配置」里管理。</div>
    <el-select v-model="alipayAccountId" placeholder="不开通支付宝转账加款" clearable style="width: 100%">
      <el-option v-for="a in alipayAccounts" :key="a.id" :label="`${a.name} · ${a.account}${a.status !== 'active' ? '（已停用）' : ''}`" :value="a.id" />
    </el-select>
    <template #footer>
      <el-button @click="receivingVisible = false">取消</el-button>
      <el-button type="primary" :loading="receivingSaving" @click="saveReceiving">保存</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.receiving-title {
  margin: 0 0 8px;
}

.receiving-title:not(:first-child) {
  margin-top: 20px;
}

.users-tip,
.user-form {
  margin-bottom: 12px;
}

.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.muted {
  color: #909399;
  font-size: 12px;
}

.warn-text {
  color: #e6a23c;
  font-size: 12px;
  display: inline-flex;
  align-items: center;
  gap: 2px;
}

.secret {
  margin-top: 12px;
}

/* AppSecret 有 64 位，窄屏时换行，不撑出弹窗 */
.secret code {
  word-break: break-all;
}

.product-tabs {
  margin-top: -8px;
}

.product-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.price-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 2px 0;
}

.price-label {
  width: 32px;
}
.receiving-tip {
  margin-bottom: 12px;
  color: #909399;
  font-size: 12px;
}

.receiving-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.receiving-tag {
  margin-left: 6px;
}
</style>
