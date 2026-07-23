import { defineStore } from 'pinia'
import { get, post } from '@/api/request'
import {
  clearAuthSession,
  readAuthSession,
  subscribeAuthSession,
  writeAuthSession,
} from '@/utils/auth-session'
import { notifyAuthCleared, rotateAuthSessionId } from '@/utils/auth'

let stopAuthSubscription = null

export const useUserStore = defineStore('user', {
  state: () => ({
    token: '',
    refreshToken: '',
    userInfo: null,
    isLoggedIn: false,
  }),
  actions: {
    applyAuthSession(session) {
      this.token = session.accessToken
      this.refreshToken = session.refreshToken
      this.isLoggedIn = !!session.accessToken
      if (!session.accessToken) {
        this.userInfo = null
      }
    },
    startAuthSync() {
      if (!stopAuthSubscription) {
        stopAuthSubscription = subscribeAuthSession((session) => {
          this.applyAuthSession(session)
        })
      }
      this.restoreToken()
    },
    restoreToken() {
      this.applyAuthSession(readAuthSession())
    },
    setToken(accessToken, refreshToken) {
      this.applyAuthSession(writeAuthSession(accessToken, refreshToken))
      rotateAuthSessionId()
    },
    clearAuth() {
      this.applyAuthSession(clearAuthSession())
      notifyAuthCleared()
    },
    async fetchUserInfo() {
      if (!this.token) {
        this.restoreToken()
      }
      if (!this.token) {
        this.userInfo = null
        this.isLoggedIn = false
        return null
      }

      try {
        const data = await get('/client/api/user/my/info')
        this.userInfo = data
        this.isLoggedIn = true
        return data
      } catch (e) {
        if (!readAuthSession().accessToken) {
          this.clearAuth()
        }
        throw e
      }
    },
    async logout() {
      try {
        await post('/client/api/user/my/logout')
      } catch (e) { /* ignore */ }
      this.clearAuth()
    }
  }
})
