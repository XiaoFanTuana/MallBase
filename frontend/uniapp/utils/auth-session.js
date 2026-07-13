const TOKEN_KEY = 'mb_access_token'
const REFRESH_KEY = 'mb_refresh_token'

const listeners = new Set()

export function readAuthSession() {
  return {
    accessToken: uni.getStorageSync(TOKEN_KEY) || '',
    refreshToken: uni.getStorageSync(REFRESH_KEY) || '',
  }
}

export function writeAuthSession(accessToken, refreshToken) {
  if (accessToken) {
    uni.setStorageSync(TOKEN_KEY, accessToken)
  }
  if (refreshToken) {
    uni.setStorageSync(REFRESH_KEY, refreshToken)
  }

  return notifyAuthSession()
}

export function clearAuthSession() {
  uni.removeStorageSync(TOKEN_KEY)
  uni.removeStorageSync(REFRESH_KEY)
  return notifyAuthSession()
}

export function subscribeAuthSession(listener) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function notifyAuthSession() {
  const session = readAuthSession()
  listeners.forEach((listener) => listener(session))
  return session
}
