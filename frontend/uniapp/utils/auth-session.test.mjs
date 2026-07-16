import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const source = readFileSync(new URL('./auth-session.js', import.meta.url), 'utf8')
const authSession = await import(
  `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`
)

function installUniStorage() {
  const storage = new Map()
  globalThis.uni = {
    getStorageSync: (key) => storage.get(key) || '',
    removeStorageSync: (key) => storage.delete(key),
    setStorageSync: (key, value) => storage.set(key, value),
  }
  return storage
}

test('auth session writes and publishes refreshed tokens', () => {
  installUniStorage()
  const published = []
  const unsubscribe = authSession.subscribeAuthSession((session) => published.push(session))

  authSession.writeAuthSession('access-1', 'refresh-1')
  authSession.writeAuthSession('access-2', '')

  assert.deepEqual(authSession.readAuthSession(), {
    accessToken: 'access-2',
    refreshToken: 'refresh-1',
  })
  assert.equal(published.length, 2)
  assert.equal(published[1].accessToken, 'access-2')
  unsubscribe()
})

test('clearing auth session notifies subscribers', () => {
  installUniStorage()
  authSession.writeAuthSession('access', 'refresh')
  let latest = null
  const unsubscribe = authSession.subscribeAuthSession((session) => {
    latest = session
  })

  authSession.clearAuthSession()

  assert.deepEqual(latest, { accessToken: '', refreshToken: '' })
  unsubscribe()
})
