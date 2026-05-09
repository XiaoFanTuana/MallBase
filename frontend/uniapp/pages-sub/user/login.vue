<template>
  <view class="login-page">
    <mb-navbar title="" bgColor="transparent" />

    <view class="login-content">
      <view class="brand">
        <view class="brand-mark">
          <image v-if="loginLogo" class="brand-mark__image" :src="loginLogo" mode="aspectFit" />
          <view v-else class="brand-mark__bag">
            <view class="brand-mark__bag-body" />
            <view class="brand-mark__bag-handle" />
          </view>
        </view>
        <text class="brand-title">{{ brandName }}</text>
        <text class="brand-subtitle">{{ brandSubtitle }}</text>
      </view>

      <!-- #ifdef MP-WEIXIN -->
      <view v-if="wechatBindStep === 'none'" class="form-section">
        <button class="btn-wechat" :class="{ 'btn-wechat--loading': loading }" @tap="handleWechatMiniLogin">
          <text class="btn-wechat-label">微信一键登录</text>
        </button>
      </view>

      <view v-else-if="wechatBindStep === 'bind'" class="form-section">
        <text class="bind-hint">请绑定手机号以完成登录</text>
        <view v-if="wechatNeedUserInfo" class="profile-card">
          <button class="avatar-picker" open-type="chooseAvatar" @chooseavatar="onChooseAvatar" :disabled="wechatAvatarUploading">
            <image v-if="wechatAvatarPreview || wechatAvatar" class="avatar-image" :src="wechatAvatarPreview || wechatAvatar" mode="aspectFill" />
            <text v-else class="avatar-plus">+</text>
          </button>
          <input
            v-model="wechatNickname"
            class="nickname-input"
            type="nickname"
            placeholder="请输入微信昵称"
            placeholder-class="placeholder"
          />
        </view>
        <button
          v-if="wechatForcePhone"
          class="btn-wechat"
          open-type="getPhoneNumber"
          @getphonenumber="onBindPhoneNumber"
        >
          <text class="btn-wechat-label">授权手机号快捷绑定</text>
        </button>
        <view v-if="!wechatForcePhone" class="methods-divider">
          <view class="divider-line" />
          <text class="divider-label">或手动输入</text>
          <view class="divider-line" />
        </view>
        <view v-if="!wechatForcePhone" class="input-line">
          <text class="area-code">+86</text>
          <text class="chevron">&#x25BE;</text>
          <input v-model="phone" class="line-input" type="number" maxlength="11"
            placeholder="请输入手机号" placeholder-class="placeholder" />
        </view>
        <view v-if="!wechatForcePhone" class="input-line">
          <text class="input-label">验证码</text>
          <input v-model="smsCode" class="line-input" type="number" maxlength="6"
            placeholder="" placeholder-class="placeholder" />
          <text class="sms-btn" :class="{ 'sms-btn--off': countdown > 0 }"
            @tap="handleSendCode('bind_mobile')">
            {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
          </text>
        </view>
        <view v-if="!wechatForcePhone" class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handleBindMobile">
          <text class="primary-btn-text">{{ loading ? '绑定中...' : '绑 定' }}</text>
        </view>
      </view>

      <view v-else-if="wechatBindStep === 'profile'" class="form-section">
        <text class="bind-hint">请完善头像昵称以完成登录</text>
        <view class="profile-card">
          <button class="avatar-picker" open-type="chooseAvatar" @chooseavatar="onChooseAvatar" :disabled="wechatAvatarUploading">
            <image v-if="wechatAvatarPreview || wechatAvatar" class="avatar-image" :src="wechatAvatarPreview || wechatAvatar" mode="aspectFill" />
            <text v-else class="avatar-plus">+</text>
          </button>
          <input
            v-model="wechatNickname"
            class="nickname-input"
            type="nickname"
            placeholder="请输入微信昵称"
            placeholder-class="placeholder"
          />
        </view>
        <view class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handleBindUserInfo">
          <text class="primary-btn-text">{{ loading ? '登录中...' : '完 成' }}</text>
        </view>
      </view>
      <!-- #endif -->

      <!-- #ifndef MP-WEIXIN -->
      <view v-if="wechatBindStep === 'bind'" class="form-section">
        <text class="bind-hint">请绑定手机号以完成登录</text>
        <view class="input-line">
          <text class="area-code">+86</text>
          <text class="chevron">&#x25BE;</text>
          <input v-model="phone" class="line-input" type="number" maxlength="11"
            placeholder="请输入手机号" placeholder-class="placeholder" />
        </view>
        <view class="input-line">
          <text class="input-label">验证码</text>
          <input v-model="smsCode" class="line-input" type="number" maxlength="6"
            placeholder="" placeholder-class="placeholder" />
          <text class="sms-btn" :class="{ 'sms-btn--off': countdown > 0 }"
            @tap="handleSendCode('wechat_official_bind')">
            {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
          </text>
        </view>
        <view class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handleOfficialBindMobile">
          <text class="primary-btn-text">{{ loading ? '绑定中...' : '绑 定' }}</text>
        </view>
      </view>

      <view v-else class="form-section auth-panel">
        <view class="login-tabs">
          <view class="login-tabs__item" :class="{ 'login-tabs__item--active': loginMode === 'sms' }" @tap="loginMode = 'sms'">
            <text class="login-tabs__text">手机号登录</text>
          </view>
          <view class="login-tabs__item" :class="{ 'login-tabs__item--active': loginMode === 'password' }" @tap="loginMode = 'password'">
            <text class="login-tabs__text">账号密码</text>
          </view>
        </view>

        <view v-if="loginMode === 'sms'" class="login-fields">
          <view class="input-line input-line--icon">
            <view class="field-icon field-icon--phone" />
            <input v-model="phone" class="line-input" type="number" maxlength="11"
              placeholder="请输入手机号" placeholder-class="placeholder" />
          </view>
          <view class="input-line input-line--icon">
            <view class="field-icon field-icon--code" />
            <input v-model="smsCode" class="line-input" type="number" maxlength="6"
              placeholder="验证码" placeholder-class="placeholder" />
            <text class="sms-btn" :class="{ 'sms-btn--off': countdown > 0 }"
              @tap="handleSendCode('login')">
              {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
            </text>
          </view>
        </view>

        <view v-else class="login-fields">
          <view class="input-line input-line--icon">
            <view class="field-icon field-icon--user" />
            <input v-model="account" class="line-input line-input--full" type="text"
              placeholder="手机号 / 用户名" placeholder-class="placeholder" />
          </view>
          <view class="input-line input-line--icon">
            <view class="field-icon field-icon--lock" />
            <input v-model="password" class="line-input line-input--full"
              :type="showPassword ? 'text' : 'password'"
              placeholder="密码" placeholder-class="placeholder" />
            <view class="eye-toggle" @tap="showPassword = !showPassword">
              <view class="eye-shape" />
              <view v-if="!showPassword" class="eye-slash" />
            </view>
          </view>
        </view>

        <view class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handlePasswordLogin">
          <text class="primary-btn-text">{{ loading ? '登录中...' : '登录' }}</text>
        </view>

        <view class="secondary-actions">
          <text class="secondary-action" @tap="handleForgotPassword">忘记密码</text>
          <text class="secondary-divider">·</text>
          <text class="secondary-action" @tap="goRegister">立即注册</text>
        </view>

        <!-- #ifdef MP-WEIXIN -->
        <view class="wechat-entry" @tap="handleWechatMiniLogin">
          <view class="wechat-entry__icon">
            <view class="wechat-entry__bubble" />
            <view class="wechat-entry__bubble wechat-entry__bubble--r" />
          </view>
          <text class="wechat-entry__text">微信一键登录</text>
        </view>
        <!-- #endif -->
        <!-- #ifndef MP-WEIXIN -->
        <view v-if="isWechatH5" class="wechat-entry" @tap="handleWechatOfficialLogin">
          <view class="wechat-entry__icon">
            <view class="wechat-entry__bubble" />
            <view class="wechat-entry__bubble wechat-entry__bubble--r" />
          </view>
          <text class="wechat-entry__text">微信一键登录</text>
        </view>
        <!-- #endif -->
      </view>
      <!-- #endif -->

      <view class="agreement">
        <view class="agree-toggle" @tap="agreed = !agreed">
          <view class="agree-box" :class="{ 'agree-box--checked': agreed }">
            <text v-if="agreed" class="agree-mark">✓</text>
          </view>
          <text class="agree-text">我已阅读并同意</text>
        </view>
        <view class="agree-links">
          <text class="agree-link" @tap.stop="openAgreement('service')">用户协议</text>
          <text class="agree-sep">与</text>
          <text class="agree-link" @tap.stop="openAgreement('privacy')">隐私政策</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue'
import { onLoad, onUnload } from '@dcloudio/uni-app'
import { useAppStore } from '@/store/app'
import { useUserStore } from '@/store/user'
import {
  sendSmsCode,
  loginBySms,
  loginByPassword,
  loginByUsername,
  wechatLogin,
  wechatBindMobile,
  wechatBindByPhoneCode,
  wechatBindUserInfo,
  uploadWechatBindAvatar,
  getWechatOfficialOauthUrl,
  wechatOfficialLogin,
  wechatOfficialBindMobile,
} from '@/api/user/auth'

const userStore = useUserStore()
const appStore = useAppStore()

const loginMode = ref('sms')
const phone = ref('')
const smsCode = ref('')
const account = ref('')
const password = ref('')
const showPassword = ref(false)
const agreed = ref(false)
const loading = ref(false)
const countdown = ref(0)
const wechatBindStep = ref('none')
const wechatBindToken = ref('')
const wechatForcePhone = ref(false)
const wechatNeedUserInfo = ref(false)
const wechatNickname = ref('')
const wechatAvatar = ref('')
const wechatAvatarPreview = ref('')
const wechatAvatarUploading = ref(false)
const isWechatH5 = ref(false)
const redirectUrl = ref('')

let countdownTimer = null

const brandName = computed(() => (
  appStore.siteConfig?.client_auth_name
  || appStore.siteConfig?.client_site_name
  || appStore.siteConfig?.site_name
  || 'MallBase'
))
const brandSubtitle = computed(() => appStore.siteConfig?.site_slogan || '欢迎回来，继续你的品质购物体验')
const loginLogo = computed(() => appStore.siteConfig?.client_auth_logo || appStore.siteConfig?.client_logo || '')

onLoad((query) => {
  if (query?.redirect) {
    redirectUrl.value = decodeURIComponent(query.redirect)
  }
})

onMounted(() => {
  if (!appStore.siteConfig) {
    appStore.fetchBasicConfig()
  }

  // #ifdef H5
  isWechatH5.value = /micromessenger/i.test(navigator.userAgent)
  handleWechatH5Callback()
  // #endif
})

onUnload(() => {
  if (countdownTimer) {
    clearInterval(countdownTimer)
    countdownTimer = null
  }
})

function startCountdown() {
  countdown.value = 60
  countdownTimer = setInterval(() => {
    countdown.value -= 1
    if (countdown.value <= 0) {
      clearInterval(countdownTimer)
      countdownTimer = null
    }
  }, 1000)
}

function validatePhone() {
  if (!/^1\d{10}$/.test(phone.value)) {
    uni.showToast({ title: '请输入正确的手机号', icon: 'none' })
    return false
  }
  return true
}

function handleForgotPassword() {
  uni.showToast({ title: '请先登录后再修改密码', icon: 'none' })
}

function checkAgreement() {
  if (!agreed.value) {
    uni.showToast({ title: '请先同意服务协议与隐私政策', icon: 'none' })
    return false
  }
  return true
}

function validateWechatProfile() {
  if (!wechatNeedUserInfo.value) return true
  if (wechatAvatarUploading.value) {
    uni.showToast({ title: '头像上传中，请稍后', icon: 'none' })
    return false
  }
  if (!wechatAvatar.value) {
    uni.showToast({ title: '请先选择微信头像', icon: 'none' })
    return false
  }
  if (!wechatNickname.value.trim()) {
    uni.showToast({ title: '请输入微信昵称', icon: 'none' })
    return false
  }
  return true
}

function getWechatProfilePayload() {
  if (!wechatNeedUserInfo.value) return {}
  return {
    avatar: wechatAvatar.value,
    nickname: wechatNickname.value.trim(),
  }
}

async function handleSendCode(scene = 'login') {
  if (countdown.value > 0) return
  if (!validatePhone()) return
  try {
    await sendSmsCode(phone.value, scene)
    startCountdown()
    uni.showToast({ title: '验证码已发送', icon: 'none' })
  } catch (_) { /* request.js shows toast */ }
}

async function onLoginSuccess(data) {
  userStore.setToken(data.access_token, data.refresh_token)
  await userStore.fetchUserInfo()
  if (redirectUrl.value) {
    const url = redirectUrl.value
    uni.redirectTo({
      url,
      fail() {
        uni.switchTab({ url: url.split('?')[0] })
      },
    })
    return
  }
  const pages = getCurrentPages()
  if (pages.length > 1) {
    uni.navigateBack()
  } else {
    uni.switchTab({ url: '/pages/index/index' })
  }
}

async function handleSmsLogin() {
  if (loading.value) return
  if (!checkAgreement() || !validatePhone()) return
  if (!smsCode.value) {
    uni.showToast({ title: '请输入验证码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    await onLoginSuccess(await loginBySms(phone.value, smsCode.value))
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}

async function handlePasswordLogin() {
  if (loading.value) return
  if (!checkAgreement()) return
  if (!account.value) {
    uni.showToast({ title: '请输入手机号或用户名', icon: 'none' })
    return
  }
  if (!password.value) {
    uni.showToast({ title: '请输入密码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    const isPhone = /^1\d{10}$/.test(account.value)
    const data = isPhone
      ? await loginByPassword(account.value, password.value)
      : await loginByUsername(account.value, password.value)
    await onLoginSuccess(data)
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}

// #ifdef MP-WEIXIN
function loginWithWechatMini() {
  return new Promise((resolve, reject) => {
    uni.login({
      provider: 'weixin',
      success: resolve,
      fail: reject,
    })
  })
}

async function handleWechatMiniLogin() {
  if (loading.value) return
  if (!checkAgreement()) return
  loading.value = true
  try {
    let loginResult
    try {
      loginResult = await loginWithWechatMini()
    } catch (_) {
      uni.showToast({ title: '微信登录失败,请重试', icon: 'none' })
      return
    }
    const { code } = loginResult
    if (!code) {
      uni.showToast({ title: '微信登录失败,请重试', icon: 'none' })
      return
    }
    const data = await wechatLogin(code)
    if (data.need_mobile || data.need_userinfo) {
      wechatBindToken.value = data.bind_token || ''
      wechatForcePhone.value = !!data.force_phone_number
      wechatNeedUserInfo.value = !!data.need_userinfo
      wechatAvatar.value = ''
      wechatAvatarPreview.value = ''
      wechatAvatarUploading.value = false
      wechatBindStep.value = data.need_mobile ? 'bind' : 'profile'
    } else {
      await onLoginSuccess(data)
    }
  } catch (_) { /* request.js shows toast */ }
  finally { loading.value = false }
}

async function onBindPhoneNumber(e) {
  if (loading.value) return
  if (!checkAgreement()) return
  if (!validateWechatProfile()) return
  if (e.detail.errMsg !== 'getPhoneNumber:ok') {
    uni.showToast({ title: '未完成手机号授权', icon: 'none' })
    return
  }
  if (!e.detail.code) {
    uni.showToast({ title: '获取手机号失败,请重试', icon: 'none' })
    return
  }
  if (!wechatBindToken.value) {
    uni.showToast({ title: '登录态已过期,请重新登录', icon: 'none' })
    wechatBindStep.value = 'none'
    return
  }
  loading.value = true
  try {
    await onLoginSuccess(await wechatBindByPhoneCode(wechatBindToken.value, e.detail.code, getWechatProfilePayload()))
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}

async function onChooseAvatar(e) {
  const avatarUrl = e.detail?.avatarUrl || ''
  if (!avatarUrl) return
  if (!wechatBindToken.value) {
    uni.showToast({ title: '登录态已过期，请重新登录', icon: 'none' })
    wechatBindStep.value = 'none'
    return
  }

  wechatAvatarPreview.value = avatarUrl
  wechatAvatar.value = ''
  wechatAvatarUploading.value = true
  try {
    const uploadRes = await uploadWechatBindAvatar(wechatBindToken.value, avatarUrl)
    if (!uploadRes?.url) {
      throw new Error('上传结果缺少头像路径')
    }
    wechatAvatar.value = uploadRes.url
  } catch (_) {
    wechatAvatarPreview.value = ''
    wechatAvatar.value = ''
  } finally {
    wechatAvatarUploading.value = false
  }
}
// #endif

async function handleBindMobile() {
  if (loading.value) return
  if (!checkAgreement()) return
  if (!validateWechatProfile()) return
  if (!wechatBindToken.value) {
    uni.showToast({ title: '登录态已过期,请重新登录', icon: 'none' })
    wechatBindStep.value = 'none'
    return
  }
  if (!validatePhone()) return
  if (!smsCode.value) {
    uni.showToast({ title: '请输入验证码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    await onLoginSuccess(await wechatBindMobile(wechatBindToken.value, phone.value, smsCode.value, getWechatProfilePayload()))
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}

async function handleBindUserInfo() {
  if (loading.value) return
  if (!checkAgreement()) return
  if (!wechatBindToken.value) {
    uni.showToast({ title: '登录态已过期,请重新登录', icon: 'none' })
    wechatBindStep.value = 'none'
    return
  }
  if (!validateWechatProfile()) return
  loading.value = true
  try {
    const data = await wechatBindUserInfo(wechatBindToken.value, getWechatProfilePayload())
    if (data.need_mobile) {
      wechatBindToken.value = data.bind_token || wechatBindToken.value
      wechatForcePhone.value = !!data.force_phone_number
      wechatNeedUserInfo.value = !!data.need_userinfo
      wechatBindStep.value = 'bind'
    } else {
      await onLoginSuccess(data)
    }
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}

async function handleOfficialBindMobile() {
  if (loading.value) return
  if (!checkAgreement()) return
  if (!wechatBindToken.value) {
    uni.showToast({ title: '登录态已过期,请重新登录', icon: 'none' })
    wechatBindStep.value = 'none'
    return
  }
  if (!validatePhone()) return
  if (!smsCode.value) {
    uni.showToast({ title: '请输入验证码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    await onLoginSuccess(await wechatOfficialBindMobile(wechatBindToken.value, phone.value, smsCode.value))
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}

// #ifdef H5
function handleWechatH5Callback() {
  if (!isWechatH5.value) return
  const url = new URL(window.location.href)
  const code = url.searchParams.get('code')
  if (!code) return
  url.searchParams.delete('code')
  url.searchParams.delete('state')
  window.history.replaceState({}, '', url.toString())
  loading.value = true
  wechatOfficialLogin(code)
    .then((data) => {
      if (data.need_mobile) {
        wechatBindToken.value = data.bind_token || ''
        wechatBindStep.value = 'bind'
        wechatForcePhone.value = false
      } else {
        return onLoginSuccess(data)
      }
    })
    .catch(() => {})
    .finally(() => { loading.value = false })
}
// #endif

async function handleWechatOfficialLogin() {
  if (!checkAgreement()) return
  // #ifdef H5
  if (isWechatH5.value) {
    loading.value = true
    try {
      const redirectUri = window.location.href.split('?')[0]
      const data = await getWechatOfficialOauthUrl(redirectUri, 'login')
      if (data.url) {
        window.location.href = data.url
      }
    } catch (_) { /* request.js shows toast */ }
    finally { loading.value = false }
  }
  // #endif
}

function openAgreement(type) {
  uni.navigateTo({
    url: `/pages-sub/user/agreement?type=${type === 'privacy' ? 'privacy' : 'service'}`,
  })
}

function goRegister() {
  uni.navigateTo({ url: '/pages-sub/user/register' })
}
</script>

<style lang="scss" scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  background: $mb-color-bg-secondary;
}

.login-content {
  width: 100%;
  max-width: 750rpx;
  display: flex;
  flex-direction: column;
  position: relative;
  z-index: 1;
  padding: 24rpx $mb-spacing-page calc(40rpx + env(safe-area-inset-bottom));
}

// ---- Brand ----
.brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  margin-bottom: 32rpx;
}

.brand-mark {
  width: 104rpx;
  height: 104rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 24rpx;
  border-radius: 28rpx;
  background: #ffffff;
  border: 1rpx solid rgba(13, 80, 213, 0.12);
}

.brand-mark__image {
  width: 104rpx;
  height: 104rpx;
  border-radius: 28rpx;
}

.brand-mark__bag {
  position: relative;
  width: 68rpx;
  height: 76rpx;
}

.brand-mark__bag-body {
  position: absolute;
  bottom: 0;
  left: 4rpx;
  right: 4rpx;
  height: 54rpx;
  border: 3rpx solid $mb-color-primary;
  border-radius: 8rpx 8rpx 14rpx 14rpx;
  background: rgba(13, 80, 213, 0.06);
}

.brand-mark__bag-handle {
  position: absolute;
  top: 2rpx;
  left: 50%;
  transform: translateX(-50%);
  width: 30rpx;
  height: 24rpx;
  border: 3rpx solid $mb-color-primary;
  border-bottom: none;
  border-radius: 16rpx 16rpx 0 0;
}

.brand-title {
  max-width: 100%;
  font-size: 52rpx;
  font-weight: 700;
  letter-spacing: 0;
  color: $mb-color-text-title;
  line-height: 1.15;
  word-break: break-word;
}

.brand-subtitle {
  margin-top: 12rpx;
  font-size: 24rpx;
  color: $mb-color-text-tertiary;
  letter-spacing: 0;
  line-height: 1.45;
}

// ---- Form ----
.form-section {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 20rpx;
  padding: 28rpx;
  background: rgba(255, 255, 255, 0.98);
  border-radius: 24rpx;
  border: 1rpx solid $mb-color-divider;
}

.auth-panel {
  margin-bottom: 24rpx;
}

.login-tabs {
  display: flex;
  align-items: center;
  padding: 6rpx;
  background: $mb-color-bg-secondary;
  border: 1rpx solid $mb-color-divider;
  border-radius: 18rpx;
}

.login-tabs__item {
  flex: 1;
  height: 72rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 14rpx;
  color: $mb-color-text-tertiary;
}

.login-tabs__item--active {
  background: #ffffff;
  color: $mb-color-primary;
  box-shadow: 0 1rpx 4rpx rgba(13, 80, 213, 0.08);
}

.login-tabs__text {
  font-size: 24rpx;
  font-weight: 600;
  letter-spacing: 0;
}

.login-fields {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}

.input-line {
  display: flex;
  align-items: center;
  min-height: 88rpx;
  border: 1rpx solid $mb-color-border;
  border-radius: $mb-radius-sm;
  background: $mb-color-bg-surface;
  padding: 0 24rpx;
  transition: border-color 0.2s, background-color 0.2s;

  &:focus-within {
    border-color: rgba(13, 80, 213, 0.45);
    background: #ffffff;
  }
}

.area-code {
  font-size: 28rpx;
  font-weight: 500;
  color: $mb-color-text;
  flex-shrink: 0;
}

.chevron {
  font-size: 20rpx;
  color: $mb-color-text-tertiary;
  margin-left: 4rpx;
  flex-shrink: 0;
}

.input-label {
  font-size: 28rpx;
  color: $mb-color-text-tertiary;
  flex-shrink: 0;
}

.input-line--icon {
  gap: 16rpx;
}

.field-icon {
  position: relative;
  flex-shrink: 0;
  width: 32rpx;
  height: 32rpx;
  color: $mb-color-text-tertiary;
}

.field-icon--phone::before {
  content: '';
  position: absolute;
  inset: 2rpx 6rpx;
  border: 3rpx solid currentColor;
  border-radius: 8rpx;
}

.field-icon--phone::after {
  content: '';
  position: absolute;
  left: 13rpx;
  bottom: 3rpx;
  width: 6rpx;
  height: 4rpx;
  border-radius: 999rpx;
  background: currentColor;
}

.field-icon--user::before {
  content: '';
  position: absolute;
  top: 2rpx;
  left: 7rpx;
  width: 18rpx;
  height: 18rpx;
  border: 3rpx solid currentColor;
  border-radius: 50%;
}

.field-icon--user::after {
  content: '';
  position: absolute;
  left: 4rpx;
  bottom: 2rpx;
  width: 24rpx;
  height: 12rpx;
  border: 3rpx solid currentColor;
  border-top: none;
  border-radius: 0 0 12rpx 12rpx;
}

.field-icon--lock::before {
  content: '';
  position: absolute;
  left: 7rpx;
  top: 12rpx;
  width: 18rpx;
  height: 14rpx;
  border: 3rpx solid currentColor;
  border-radius: 4rpx;
}

.field-icon--lock::after {
  content: '';
  position: absolute;
  left: 11rpx;
  top: 4rpx;
  width: 10rpx;
  height: 10rpx;
  border: 3rpx solid currentColor;
  border-bottom: none;
  border-radius: 8rpx 8rpx 0 0;
}

.field-icon--code::before {
  content: '';
  position: absolute;
  inset: 6rpx 4rpx;
  border: 3rpx solid currentColor;
  border-radius: 6rpx;
}

.field-icon--code::after {
  content: '';
  position: absolute;
  left: 10rpx;
  top: 13rpx;
  width: 12rpx;
  height: 3rpx;
  background: currentColor;
  box-shadow: 0 -7rpx 0 currentColor;
}

.line-input {
  flex: 1;
  min-width: 0;
  height: 100%;
  font-size: 28rpx;
  color: $mb-color-text;
  margin-left: 0;
}

.line-input--full {
  margin-left: 0;
}

.placeholder {
  color: $mb-color-border-light;
}

.sms-btn {
  flex-shrink: 0;
  font-size: 24rpx;
  font-weight: 600;
  color: $mb-color-primary;
  white-space: nowrap;
  letter-spacing: 0;
  padding-left: 18rpx;
}

.sms-btn--off {
  color: $mb-color-border-light;
}

// ---- Eye toggle ----
.eye-toggle {
  flex-shrink: 0;
  width: 56rpx;
  height: 56rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.eye-shape {
  width: 36rpx;
  height: 24rpx;
  border: 2rpx solid $mb-color-text-tertiary;
  border-radius: 50%;
  position: relative;

  &::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 10rpx;
    height: 10rpx;
    border-radius: 50%;
    background: $mb-color-text-tertiary;
  }
}

.eye-slash {
  position: absolute;
  top: 12rpx;
  left: 50%;
  width: 2rpx;
  height: 32rpx;
  background: $mb-color-text-tertiary;
  transform: translateX(-50%) rotate(45deg);
}

// ---- Buttons ----
.primary-btn {
  height: 92rpx;
  border-radius: $mb-radius-sm;
  background: $mb-color-primary;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 4rpx;
  transition: opacity 0.15s, transform 0.15s;

  &:active {
    opacity: 0.85;
    transform: scale(0.985);
  }
}

.primary-btn--loading {
  opacity: 0.7;
  pointer-events: none;
}

.primary-btn-text {
  font-size: 30rpx;
  font-weight: 600;
  color: $mb-color-text-inverse;
  letter-spacing: 0;
}

.btn-wechat {
  width: 100%;
  height: 92rpx;
  border-radius: $mb-radius-sm;
  background: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14rpx;
  border: 1rpx solid rgba(7, 193, 96, 0.16);
  padding: 0;
  margin: 0;

  &::after {
    display: none;
  }

  &:active {
    opacity: 0.88;
  }
}

.btn-wechat--loading {
  opacity: 0.7;
  pointer-events: none;
}

.btn-wechat-label {
  font-size: 26rpx;
  font-weight: 600;
  color: $mb-color-text;
  letter-spacing: 0;
}

.secondary-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12rpx;
  padding-top: 4rpx;
}

.secondary-action {
  font-size: 22rpx;
  color: $mb-color-text-secondary;
}

.secondary-divider {
  font-size: 20rpx;
  color: $mb-color-border-light;
}

.wechat-entry {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14rpx;
  height: 84rpx;
  border-radius: $mb-radius-sm;
  border: 1rpx solid $mb-color-border;
  background: $mb-color-bg;
}

.wechat-entry__icon {
  position: relative;
  width: 34rpx;
  height: 28rpx;
}

.wechat-entry__bubble {
  position: absolute;
  bottom: 0;
  left: 0;
  width: 22rpx;
  height: 18rpx;
  border-radius: 999rpx;
  background: #07c160;

  &::after {
    content: '';
    position: absolute;
    bottom: -3rpx;
    left: 4rpx;
    width: 8rpx;
    height: 6rpx;
    background: #07c160;
    clip-path: polygon(0 0, 100% 0, 35% 100%);
  }
}

.wechat-entry__bubble--r {
  left: auto;
  right: 0;
  bottom: 6rpx;
  width: 18rpx;
  height: 14rpx;

  &::after {
    left: auto;
    right: 3rpx;
    clip-path: polygon(0 0, 100% 0, 65% 100%);
  }
}

.wechat-entry__text {
  font-size: 24rpx;
  color: $mb-color-text;
  font-weight: 500;
}

.bind-hint {
  font-size: 24rpx;
  color: $mb-color-text-secondary;
  text-align: center;
  margin-bottom: $mb-spacing-sm;
}

.profile-card {
  display: flex;
  align-items: center;
  gap: 24rpx;
  margin-bottom: 20rpx;
  padding: 20rpx 0;
  border-bottom: 1rpx solid $mb-color-divider;
}

.avatar-picker {
  width: 96rpx;
  height: 96rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-bg-secondary;
  border: 2rpx solid $mb-color-border;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  margin: 0;
  overflow: hidden;

  &::after {
    display: none;
  }
}

.avatar-image {
  width: 96rpx;
  height: 96rpx;
}

.avatar-plus {
  font-size: 44rpx;
  color: $mb-color-text-tertiary;
  line-height: 1;
}

.nickname-input {
  flex: 1;
  height: 80rpx;
  font-size: 30rpx;
  color: $mb-color-text;
}

// ---- Dividers (WeChat bind only) ----
.methods-divider {
  display: flex;
  align-items: center;
  gap: $mb-spacing-md;
  margin-bottom: 24rpx;
}

.divider-line {
  flex: 1;
  height: 2rpx;
  background: $mb-color-divider;
}

.divider-label {
  font-size: $mb-font-sm;
  color: $mb-color-border-light;
  white-space: nowrap;
}

// ---- Agreement ----
.agreement {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-top: 16rpx;
  padding: 0 20rpx;
}

.agree-toggle {
  display: flex;
  align-items: center;
  gap: 10rpx;
  margin-bottom: 8rpx;
}

.agree-box {
  width: 28rpx;
  height: 28rpx;
  border: 2rpx solid $mb-color-border;
  border-radius: $mb-radius-sm;
  display: flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
}

.agree-box--checked {
  background: $mb-color-primary;
  border-color: $mb-color-primary;
}

.agree-mark {
  font-size: 20rpx;
  line-height: 1;
  color: $mb-color-text-inverse;
}

.agree-text {
  font-size: 20rpx;
  color: $mb-color-text-tertiary;
}

.agree-links {
  display: flex;
  align-items: center;
  gap: 8rpx;
  flex-wrap: wrap;
  justify-content: center;
}

.agree-link {
  font-size: 20rpx;
  color: $mb-color-primary;
  font-weight: 500;
}

.agree-sep {
  font-size: 20rpx;
  color: $mb-color-text-tertiary;
}
</style>
