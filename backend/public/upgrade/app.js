const POLL_MS = 3000
const state = { status: null, pending: false, timer: null }

const byId = (id) => document.getElementById(id)
const text = (node, value) => { node.textContent = String(value ?? '') }

async function request(path, options = {}) {
  const response = await fetch(path, {
    credentials: 'same-origin',
    cache: 'no-store',
    ...options,
    headers: { Accept: 'application/json', ...(options.headers || {}) },
  })
  const body = await response.json().catch(() => null)
  if (!response.ok || !body || body.code >= 400) {
    const error = new Error(body?.message || `请求失败（${response.status}）`)
    error.status = response.status
    throw error
  }
  return body.data
}

function pendingMutation(key, body) {
  const storageKey = `mallbase.upgrade.pending.${key}`
  const encoded = JSON.stringify(body)
  try {
    const existing = JSON.parse(sessionStorage.getItem(storageKey) || 'null')
    if (existing?.body === encoded && typeof existing.requestId === 'string') {
      return { storageKey, requestId: existing.requestId }
    }
  } catch (_) {
    sessionStorage.removeItem(storageKey)
  }
  const requestId = crypto.randomUUID()
  sessionStorage.setItem(storageKey, JSON.stringify({ requestId, body: encoded }))
  return { storageKey, requestId }
}

async function mutate(key, path, body) {
  const pending = pendingMutation(key, body)
  const result = await request(path, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': pending.requestId,
      'X-Upgrade-CSRF': state.status.csrf_nonce,
    },
    body: JSON.stringify(body),
  })
  sessionStorage.removeItem(pending.storageKey)
  return result
}

function renderCommands(commands) {
  const root = byId('commands')
  root.replaceChildren()
  for (const architecture of ['amd64', 'arm64']) {
    const value = commands?.[architecture]
    if (!value) continue
    const block = document.createElement('div')
    block.className = 'command'
    block.textContent = `${architecture}: ${value}`
    root.append(block)
  }
}

function renderReleases(status) {
  const root = byId('releases')
  root.replaceChildren()
  const enabled = status.agent.online && status.agent.upgrade_ready && status.catalog.available
  const releases = Array.isArray(status.catalog.releases) ? status.catalog.releases : []
  if (!enabled || releases.length === 0) {
    const empty = document.createElement('p')
    empty.className = 'muted'
    empty.textContent = status.agent.online
      ? '暂时没有可用于当前版本的兼容发布。'
      : '请先在宿主机终端启动 Agent。'
    root.append(empty)
    return
  }
  for (const release of releases) {
    const card = document.createElement('article')
    card.className = 'release'
    const copy = document.createElement('div')
    const title = document.createElement('h3')
    title.textContent = `v${release.version}`
    const summary = document.createElement('p')
    summary.textContent = release.summary
    copy.append(title, summary)
    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'primary'
    button.textContent = '选择此版本'
    button.disabled = state.pending || !status.allowed_actions.includes('create_job')
    button.addEventListener('click', () => createJob(release.version, status.session.revision))
    card.append(copy, button)
    root.append(card)
  }
}

function renderTimeline(status) {
  const labels = {
    normal: '等待选择版本', preparing: '升级预检', ready_to_drain: '等待开始排空',
    draining: '正在等待请求、队列和定时任务结束', paused: '系统已静止',
    backing_up: '正在备份', applying: '正在应用升级包', awaiting_deployment: '等待用户部署新镜像',
    verifying: '正在验证新版本', reconciling: '正在执行支付与退款对账',
    completed: '升级完成', cancelled: '升级已取消', failed_pre_apply: '预检失败',
    failed_maintenance: '维护中失败，需要恢复',
  }
  const root = byId('timeline')
  root.replaceChildren()
  const current = status.job?.state || status.gate?.state || status.maintenance.state
  for (const [value, label] of Object.entries(labels)) {
    const item = document.createElement('li')
    item.textContent = label
    if (value === current) item.className = 'current'
    root.append(item)
  }
  const notice = byId('notice')
  text(notice, current === 'completed' && status.job?.safe_to_stop ? '升级已完成，现在可以在终端按 Ctrl+C 关闭 Agent。' : '')
}

function actionButton(label, action, handler, danger = false) {
  const button = document.createElement('button')
  button.type = 'button'
  button.className = danger ? 'danger' : 'secondary'
  button.textContent = label
  button.disabled = state.pending || !state.status.allowed_actions.includes(action)
  button.addEventListener('click', handler)
  return button
}

function renderActions(status) {
  const root = byId('actions')
  root.replaceChildren()
  const job = status.job
  if (!job) return
  if (status.allowed_actions.includes('start_drain')) {
    root.append(actionButton('开始安全排空', 'start_drain', () => startDrain(job.job_id, status.gate.revision)))
  }
  const labels = { cancel: '取消升级', resume: '继续恢复', rollback: '回滚文件' }
  for (const action of ['cancel', 'resume', 'rollback']) {
    if (!status.allowed_actions.includes(action)) continue
    root.append(actionButton(labels[action], action, () => controlJob(job.job_id, job.revision, action), action !== 'resume'))
  }
}

async function copyDeploymentCommand(command, button) {
  try {
    await navigator.clipboard.writeText(command)
    text(byId('copy-status'), '命令已复制，请在商城根目录的宿主机终端中执行。')
    button.textContent = '已复制'
  } catch (_) {
    text(byId('copy-status'), '复制失败，请手动选择并复制命令。')
  }
}

function renderDeployment(status) {
  const root = byId('deployment-panel')
  const commandRoot = byId('deployment-commands')
  const stepRoot = byId('deployment-steps')
  const riskRoot = byId('deployment-risks')
  root.hidden = true
  commandRoot.replaceChildren()
  stepRoot.replaceChildren()
  riskRoot.replaceChildren()
  text(byId('deployment-target'), '')
  text(byId('copy-status'), '')

  const deployment = status.deployment
  if (status.job?.state !== 'awaiting_deployment' || !deployment) return
  const commands = deployment.commands
  const steps = Array.isArray(deployment.steps) ? deployment.steps : []
  const risks = Array.isArray(deployment.risks) ? deployment.risks : []
  if (typeof deployment.target_version !== 'string' || !commands
    || typeof commands.build !== 'string' || typeof commands.start !== 'string') return

  root.hidden = false
  text(byId('deployment-target'), `v${deployment.target_version}`)
  for (const step of steps) {
    if (!step || typeof step.title !== 'string' || typeof step.description !== 'string') continue
    const item = document.createElement('li')
    const title = document.createElement('strong')
    const description = document.createElement('p')
    title.textContent = step.title
    description.textContent = step.description
    item.append(title, description)
    stepRoot.append(item)
  }
  for (const [key, label] of [['build', '构建镜像'], ['start', '启动服务']]) {
    const command = commands[key]
    const row = document.createElement('div')
    row.className = 'command-row'
    const block = document.createElement('code')
    block.className = 'command'
    block.textContent = command
    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'secondary copy-command'
    button.textContent = `复制${label}命令`
    button.addEventListener('click', () => copyDeploymentCommand(command, button))
    row.append(block, button)
    commandRoot.append(row)
  }
  for (const risk of risks) {
    if (typeof risk !== 'string') continue
    const item = document.createElement('li')
    item.textContent = risk
    riskRoot.append(item)
  }
}

function render(status) {
  state.status = status
  const online = Boolean(status.agent.online)
  const badge = byId('agent-badge')
  badge.className = `badge${online ? ' online' : ''}`
  text(badge, online ? 'Agent 已连接' : 'Agent 未启动')
  text(byId('summary'), online ? 'Agent 已就绪，请按页面步骤完成升级。' : '商城仍可正常运行；需要升级时请临时启动 Agent。')
  text(byId('agent-detail'), online
    ? `Agent ${status.agent.version} · ${status.agent.arch} · 租约至 ${new Date(status.agent.lease_until * 1000).toLocaleTimeString()}`
    : '未检测到有效租约。启动后本页面会自动刷新。')
  renderCommands(status.start_commands)
  renderReleases(status)
  renderTimeline(status)
  renderActions(status)
  renderDeployment(status)
}

async function createJob(version, revision) {
  state.pending = true
  renderReleases(state.status)
  try {
    await mutate('create', '/upgrade/api/jobs', { target_version: version, expected_revision: revision })
    await fetchStatus()
  } catch (error) {
    text(byId('notice'), error.message)
  } finally {
    state.pending = false
    if (state.status) render(state.status)
  }
}

async function startDrain(jobId, revision) {
  await runControl('drain', `/upgrade/api/jobs/${encodeURIComponent(jobId)}/drain`, { expected_revision: revision })
}

async function controlJob(jobId, revision, action) {
  await runControl(`${action}.${jobId}.${revision}`, `/upgrade/api/jobs/${encodeURIComponent(jobId)}/control`, {
    action,
    expected_revision: revision,
  })
}

async function runControl(key, path, body) {
  state.pending = true
  render(state.status)
  try {
    await mutate(key, path, body)
    await fetchStatus()
  } catch (error) {
    text(byId('notice'), error.message)
  } finally {
    state.pending = false
    if (state.status) render(state.status)
  }
}

async function fetchStatus() {
  try {
    const status = await request('/upgrade/api/status')
    render(status)
  } catch (error) {
    if (error.status === 401) {
      text(byId('summary'), '升级会话已失效，请返回后台重新进入或使用恢复凭据接管。')
      return
    }
    text(byId('summary'), '连接暂时中断，正在重试；最后一次安全状态仍保留在页面。')
  } finally {
    clearTimeout(state.timer)
    state.timer = setTimeout(fetchStatus, POLL_MS)
  }
}

byId('refresh').addEventListener('click', fetchStatus)
fetchStatus()
