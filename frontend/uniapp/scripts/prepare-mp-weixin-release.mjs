import { readFile, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'

const APP_ID_PATTERN = /^wx[a-zA-Z0-9]{16}$/

function releaseConfig() {
  const appid = String(process.env.UNIAPP_WEIXIN_APPID || '').trim()
  const baseUrl = String(process.env.VITE_UNIAPP_BASE_URL || '').trim()

  if (!APP_ID_PATTERN.test(appid)) {
    throw new Error('UNIAPP_WEIXIN_APPID must be a valid 18-character WeChat Mini Program AppID')
  }

  let parsedBaseUrl
  try {
    parsedBaseUrl = new URL(baseUrl)
  } catch {
    throw new Error('VITE_UNIAPP_BASE_URL must be an absolute HTTPS origin')
  }

  if (
    parsedBaseUrl.protocol !== 'https:' ||
    parsedBaseUrl.username ||
    parsedBaseUrl.password ||
    parsedBaseUrl.pathname !== '/' ||
    parsedBaseUrl.search ||
    parsedBaseUrl.hash ||
    baseUrl !== parsedBaseUrl.origin
  ) {
    throw new Error('VITE_UNIAPP_BASE_URL must be a normalized HTTPS origin without credentials, path, query, hash, or trailing slash')
  }

  return { appid, baseUrl: parsedBaseUrl.origin }
}

async function updateProjectConfig(appid) {
  const outputDir = resolve(process.cwd(), 'dist/build/mp-weixin')
  const appConfigPath = resolve(outputDir, 'app.json')
  const projectConfigPath = resolve(outputDir, 'project.config.json')

  await readFile(appConfigPath, 'utf8')

  const projectConfig = JSON.parse(await readFile(projectConfigPath, 'utf8'))
  if (!projectConfig || typeof projectConfig !== 'object' || Array.isArray(projectConfig)) {
    throw new Error('Generated project.config.json must contain a JSON object')
  }

  projectConfig.appid = appid
  await writeFile(projectConfigPath, `${JSON.stringify(projectConfig, null, 2)}\n`, 'utf8')
}

const { appid, baseUrl } = releaseConfig()

if (process.argv.includes('--check-only')) {
  console.log(`WeChat Mini Program release configuration is valid for ${baseUrl}`)
} else {
  await updateProjectConfig(appid)
  console.log('WeChat Mini Program release artifact is configured')
}
