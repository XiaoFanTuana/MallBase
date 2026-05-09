<script setup>
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getRefundDetail, cancelRefund } from '@/api/order/refund'

const STATUS_CONFIG = {
  0: { label: '待审核', color: '#e08a00', bg: 'rgba(224, 138, 0, 0.1)' },
  1: { label: '已同意', color: '#25a350', bg: 'rgba(37, 163, 80, 0.1)' },
  2: { label: '已拒绝', color: '#ba1a1a', bg: 'rgba(186, 26, 26, 0.1)' },
  3: { label: '已退款', color: '#0d50d5', bg: 'rgba(13, 80, 213, 0.08)' },
  4: { label: '已取消', color: '#737686', bg: 'rgba(115, 118, 134, 0.08)' },
}

const TIMELINE_STEPS = {
  0: [
    { label: '提交申请', done: true },
    { label: '商家审核', done: false, active: true },
    { label: '退款完成', done: false },
  ],
  1: [
    { label: '提交申请', done: true },
    { label: '商家审核', done: true },
    { label: '退款处理中', done: false, active: true },
  ],
  2: [
    { label: '提交申请', done: true },
    { label: '商家已拒绝', done: true, rejected: true },
  ],
  3: [
    { label: '提交申请', done: true },
    { label: '商家审核', done: true },
    { label: '退款完成', done: true },
  ],
  4: [
    { label: '提交申请', done: true },
    { label: '已取消', done: true },
  ],
}

const refundId = ref('')
const detail = ref(null)
const loading = ref(true)
const cancelling = ref(false)

onLoad((query) => {
  refundId.value = query?.id || ''
  if (refundId.value) {
    fetchDetail()
  } else {
    loading.value = false
  }
})

async function fetchDetail() {
  loading.value = true
  try {
    const data = await getRefundDetail(refundId.value)
    detail.value = data ?? null
  } catch {
    detail.value = null
  } finally {
    loading.value = false
  }
}

const statusConfig = computed(() => {
  if (!detail.value) return STATUS_CONFIG[0]
  return STATUS_CONFIG[detail.value.status] || STATUS_CONFIG[0]
})

const timelineSteps = computed(() => {
  if (!detail.value) return []
  return TIMELINE_STEPS[detail.value.status] || TIMELINE_STEPS[0]
})

const canCancel = computed(() => {
  return detail.value && detail.value.status === 0
})

function formatPrice(val) {
  const num = Number(val)
  if (Number.isNaN(num)) return '0.00'
  return num.toFixed(2)
}

function onPreviewImage(index) {
  const urls = detail.value?.images || []
  if (urls.length === 0) return
  uni.previewImage({
    current: urls[index],
    urls,
  })
}

function onCancelRefund() {
  if (cancelling.value) return
  uni.showModal({
    title: '提示',
    content: '确定要取消退款申请吗？',
    success: async (res) => {
      if (!res.confirm) return
      cancelling.value = true
      try {
        await cancelRefund(refundId.value)
        uni.showToast({ title: '已取消退款', icon: 'success' })
        fetchDetail()
      } catch {
        // error handled by request interceptor
      } finally {
        cancelling.value = false
      }
    },
  })
}
</script>

<template>
  <view class="page">
    <mb-navbar title="退款详情" />

    <!-- Loading -->
    <view v-if="loading" class="loading-wrap">
      <mb-skeleton type="card" />
      <mb-skeleton type="lines" :count="3" />
      <mb-skeleton type="avatar-lines" />
    </view>

    <!-- Empty -->
    <mb-empty-state
      v-else-if="!detail"
      text="退款记录不存在"
      action-text="返回"
      @action="() => uni.navigateBack()"
    />

    <!-- Main content -->
    <template v-else>
      <!-- Status header -->
      <view class="status-header" :style="{ background: statusConfig.bg }">
        <text class="status-header__label" :style="{ color: statusConfig.color }">{{ statusConfig.label }}</text>
        <text v-if="detail.status === 0" class="status-header__hint">
          商家正在审核您的退款申请
        </text>
        <text v-else-if="detail.status === 1" class="status-header__hint">
          退款已同意，正在处理中
        </text>
        <text v-else-if="detail.status === 2" class="status-header__hint">
          商家拒绝了您的退款申请
        </text>
        <text v-else-if="detail.status === 3" class="status-header__hint">
          退款已到账，请查收
        </text>
        <text v-else-if="detail.status === 4" class="status-header__hint">
          退款申请已取消
        </text>
      </view>

      <!-- Timeline -->
      <view class="card">
        <text class="card__title">退款进度</text>
        <view class="timeline">
          <view
            v-for="(step, idx) in timelineSteps"
            :key="idx"
            class="timeline__item"
          >
            <view class="timeline__indicator">
              <view
                class="timeline__dot"
                :class="{
                  'timeline__dot--done': step.done && !step.rejected,
                  'timeline__dot--active': step.active,
                  'timeline__dot--rejected': step.rejected,
                }"
              />
              <view
                v-if="idx < timelineSteps.length - 1"
                class="timeline__line"
                :class="{ 'timeline__line--done': step.done }"
              />
            </view>
            <view class="timeline__content">
              <text
                class="timeline__label"
                :class="{
                  'timeline__label--active': step.active,
                  'timeline__label--rejected': step.rejected,
                }"
              >{{ step.label }}</text>
            </view>
          </view>
        </view>
      </view>

      <!-- Product info -->
      <view class="card">
        <text class="card__title">商品信息</text>
        <view class="product-row">
          <image
            v-if="detail.goods_image"
            class="product-row__image"
            :src="detail.goods_image"
            mode="aspectFill"
          />
          <view v-else class="product-row__image product-row__image--placeholder">
            <view class="product-row__placeholder-box" />
          </view>
          <view class="product-row__info">
            <text class="product-row__name">{{ detail.goods_name || '商品' }}</text>
            <text v-if="detail.sku_spec_text || detail.sku_spec" class="product-row__spec">
              {{ detail.sku_spec_text || detail.sku_spec }}
            </text>
          </view>
        </view>
      </view>

      <!-- Refund info -->
      <view class="card">
        <text class="card__title">退款信息</text>
        <view class="info-row">
          <text class="info-row__label">退款原因</text>
          <text class="info-row__value">{{ detail.reason || '-' }}</text>
        </view>
        <view v-if="detail.description" class="info-row info-row--column">
          <text class="info-row__label">退款说明</text>
          <text class="info-row__desc">{{ detail.description }}</text>
        </view>
        <view v-if="detail.images && detail.images.length > 0" class="info-row info-row--column">
          <text class="info-row__label">凭证图片</text>
          <view class="evidence-grid">
            <image
              v-for="(img, idx) in detail.images"
              :key="idx"
              class="evidence-grid__img"
              :src="img"
              mode="aspectFill"
              @tap="onPreviewImage(idx)"
            />
          </view>
        </view>
      </view>

      <!-- Amount info -->
      <view class="card">
        <text class="card__title">退款金额</text>
        <view class="amount-row">
          <text class="amount-row__label">退款金额</text>
          <view class="amount-row__value-wrap">
            <text class="amount-row__symbol">{{ '¥' }}</text>
            <text class="amount-row__value">
              {{ formatPrice(detail.refund_amount || detail.amount) }}
            </text>
          </view>
        </view>
        <view v-if="detail.refund_method" class="amount-row">
          <text class="amount-row__label">退款方式</text>
          <text class="amount-row__method">{{ detail.refund_method }}</text>
        </view>
      </view>

      <!-- Bottom action -->
      <view v-if="canCancel" class="bottom-spacer" />
      <view v-if="canCancel" class="action-bar">
        <view class="action-bar__inner">
          <view
            class="action-bar__btn"
            :class="{ 'action-bar__btn--disabled': cancelling }"
            @tap="onCancelRefund"
          >
            <text class="action-bar__btn-text">
              {{ cancelling ? '取消中...' : '取消退款' }}
            </text>
          </view>
        </view>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: $mb-color-bg-secondary;
  padding: 0 $mb-spacing-page $mb-spacing-xl;
}

// ---- Loading ----
.loading-wrap {
  padding-top: $mb-spacing-lg;
  display: flex;
  flex-direction: column;
  gap: $mb-spacing-md;
}

// ---- Status header ----
.status-header {
  margin: $mb-spacing-md 0;
  padding: $mb-spacing-lg;
  position: relative;
  overflow: hidden;
  border-radius: $mb-radius-lg;
  border: 1rpx solid $mb-color-divider;
}

.status-header::after {
  display: none;
}

.status-header__label {
  display: block;
  font-size: $mb-font-xl;
  font-weight: 700;
  color: $mb-color-primary;
  position: relative;
  z-index: 1;
}

.status-header__hint {
  display: block;
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  margin-top: $mb-spacing-xs;
  position: relative;
  z-index: 1;
}

// ---- Card base ----
.card {
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg;
  margin-bottom: $mb-spacing-md;
  border: 1rpx solid $mb-color-border;
}

.card__title {
  display: block;
  font-size: $mb-font-md;
  font-weight: 600;
  color: $mb-color-text-title;
  margin-bottom: $mb-spacing-md;
}

// ---- Timeline ----
.timeline {
  padding-left: $mb-spacing-xs;
}

.timeline__item {
  display: flex;
  gap: $mb-spacing-md;
  min-height: 80rpx;

  &:last-child {
    min-height: auto;
  }
}

.timeline__indicator {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 24rpx;
  flex-shrink: 0;
}

.timeline__dot {
  width: 20rpx;
  height: 20rpx;
  border-radius: 50%;
  background: $mb-color-border;
  flex-shrink: 0;
}

.timeline__dot--done {
  background: $mb-color-success;
}

.timeline__dot--active {
  background: $mb-color-primary;
  border: 6rpx solid rgba($mb-color-primary, 0.14);
  box-sizing: content-box;
}

.timeline__dot--rejected {
  background: $mb-color-error;
}

.timeline__line {
  flex: 1;
  width: 2rpx;
  background: $mb-color-border;
  margin: 4rpx 0;
}

.timeline__line--done {
  background: $mb-color-success;
}

.timeline__content {
  flex: 1;
  padding-bottom: $mb-spacing-md;
}

.timeline__label {
  font-size: $mb-font-md;
  color: $mb-color-text-tertiary;
  line-height: 1.4;
}

.timeline__label--active {
  color: $mb-color-primary;
  font-weight: 600;
}

.timeline__label--rejected {
  color: $mb-color-error;
  font-weight: 600;
}

// ---- Product row ----
.product-row {
  display: flex;
  gap: $mb-spacing-md;
}

.product-row__image {
  flex-shrink: 0;
  width: 120rpx;
  height: 120rpx;
  border-radius: $mb-radius-md;
  background: $mb-color-bg-secondary;
}

.product-row__image--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
}

.product-row__placeholder-box {
  width: 42rpx;
  height: 34rpx;
  border: 4rpx solid $mb-color-primary;
  border-radius: 8rpx;
  position: relative;

  &::after {
    content: '';
    position: absolute;
    left: 9rpx;
    right: 9rpx;
    top: 9rpx;
    height: 4rpx;
    border-radius: $mb-radius-full;
    background: $mb-color-primary;
    opacity: 0.5;
  }
}

.product-row__info {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  min-width: 0;
}

.product-row__name {
  font-size: $mb-font-md;
  font-weight: 500;
  color: $mb-color-text-title;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.product-row__spec {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  margin-top: 8rpx;
}

// ---- Info rows ----
.info-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12rpx 0;

  & + & {
    border-top: 1rpx solid $mb-color-divider;
  }
}

.info-row--column {
  flex-direction: column;
  align-items: flex-start;
  gap: $mb-spacing-sm;
}

.info-row__label {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  flex-shrink: 0;
}

.info-row__value {
  font-size: $mb-font-md;
  color: $mb-color-text;
  text-align: right;
  flex: 1;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  margin-left: $mb-spacing-md;
}

.info-row__desc {
  font-size: $mb-font-md;
  color: $mb-color-text;
  line-height: 1.6;
  word-break: break-all;
}

// ---- Evidence grid ----
.evidence-grid {
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-sm;
}

.evidence-grid__img {
  width: 180rpx;
  height: 180rpx;
  border-radius: $mb-radius-md;
  background: $mb-color-bg-secondary;
}

// ---- Amount row ----
.amount-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12rpx 0;

  & + & {
    border-top: 1rpx solid $mb-color-divider;
  }
}

.amount-row__label {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
}

.amount-row__value-wrap {
  display: flex;
  align-items: baseline;
}

.amount-row__symbol {
  font-size: $mb-font-md;
  font-weight: 700;
  color: $mb-color-primary;
  margin-right: 4rpx;
}

.amount-row__value {
  font-size: $mb-font-xl;
  font-weight: 700;
  color: $mb-color-primary;
}

.amount-row__method {
  font-size: $mb-font-md;
  color: $mb-color-text;
}

// ---- Bottom action bar ----
.bottom-spacer {
  height: calc(140rpx + env(safe-area-inset-bottom));
}

.action-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 100;
  background: $mb-color-bg;
  box-shadow: $mb-shadow-bar;
}

.action-bar__inner {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  padding: $mb-spacing-sm $mb-spacing-page;
  padding-bottom: calc(#{$mb-spacing-sm} + env(safe-area-inset-bottom));
}

.action-bar__btn {
  height: 76rpx;
  min-width: 200rpx;
  border-radius: $mb-radius-sm;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 $mb-spacing-lg;
  border: 2rpx solid $mb-color-border;
  transition: opacity 0.15s;

  &:active {
    opacity: 0.85;
  }
}

.action-bar__btn--disabled {
  opacity: 0.5;
  pointer-events: none;
}

.action-bar__btn-text {
  font-size: $mb-font-md;
  font-weight: 600;
  color: $mb-color-text;
}
</style>
