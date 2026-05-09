<script setup>
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'

const sn = ref('')
const orderId = ref('')
const status = ref('fail')
const amount = ref('')

const isSuccess = computed(() => status.value === 'success')

onLoad((query) => {
  sn.value = query?.sn || ''
  orderId.value = query?.order_id || ''
  status.value = query?.status === 'success' ? 'success' : 'fail'
  amount.value = query?.amount || ''
})

function goOrderDetail() {
  uni.redirectTo({ url: `/pages-sub/order/detail?id=${orderId.value}` })
}

function goHome() {
  uni.switchTab({ url: '/pages/index/index' })
}

function goRetryPay() {
  uni.navigateBack()
}
</script>

<template>
  <view class="page">
    <mb-navbar title="支付结果" />

    <view class="result">
      <!-- status icon -->
      <view class="result__icon" :class="isSuccess ? 'result__icon--success' : 'result__icon--fail'">
        <text
          class="result__icon-symbol"
          :class="isSuccess ? 'result__icon-symbol--success' : 'result__icon-symbol--fail'"
        >{{ isSuccess ? '✓' : '✕' }}</text>
      </view>

      <!-- status text -->
      <text class="result__title">{{ isSuccess ? '支付成功' : '支付失败' }}</text>

      <!-- amount -->
      <text v-if="isSuccess && amount" class="result__amount">¥ {{ amount }}</text>

      <!-- order number -->
      <text class="result__sn">订单编号：{{ sn }}</text>

      <!-- action buttons -->
      <view class="result__actions">
        <template v-if="isSuccess">
          <view class="result__btn result__btn--primary" @tap="goOrderDetail">
            <text class="result__btn-text result__btn-text--primary">查看订单</text>
          </view>
          <view class="result__btn result__btn--outline" @tap="goHome">
            <text class="result__btn-text result__btn-text--outline">继续购物</text>
          </view>
        </template>
        <template v-else>
          <view class="result__btn result__btn--primary" @tap="goRetryPay">
            <text class="result__btn-text result__btn-text--primary">重新支付</text>
          </view>
          <view class="result__btn result__btn--outline" @tap="goOrderDetail">
            <text class="result__btn-text result__btn-text--outline">查看订单</text>
          </view>
        </template>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background-color: $mb-color-bg-secondary;
}

.result {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin: $mb-spacing-xl $mb-spacing-page 0;
  padding: 96rpx 48rpx $mb-spacing-xl;
  background: $mb-color-bg;
  border: 1rpx solid $mb-color-divider;
  border-radius: $mb-radius-lg;

  &__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 120rpx;
    height: 120rpx;
    border-radius: 50%;

    &--success {
      background-color: rgba(52, 199, 89, 0.1);
    }

    &--fail {
      background-color: rgba(186, 26, 26, 0.1);
    }
  }

  &__icon-symbol {
    font-size: 56rpx;
    font-weight: 700;
    color: $mb-color-primary;
    line-height: 1;
  }

  &__icon-symbol--success {
    color: $mb-color-success;
  }

  &__icon-symbol--fail {
    color: $mb-color-error;
  }

  &__title {
    margin-top: 32rpx;
    font-size: 36rpx;
    font-weight: 600;
    color: $mb-color-text-title;
  }

  &__amount {
    margin-top: 16rpx;
    font-size: 56rpx;
    font-weight: 800;
    color: $mb-color-text-title;
    letter-spacing: 2rpx;
  }

  &__sn {
    margin-top: 16rpx;
    font-size: 24rpx;
    color: $mb-color-text-tertiary;
  }

  &__actions {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    margin-top: 80rpx;
    gap: 24rpx;
  }

  &__btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 480rpx;
    height: 96rpx;
    border-radius: $mb-radius-sm;
    transition: opacity 0.15s, transform 0.15s;

    &:active {
      opacity: 0.85;
      transform: scale(0.98);
    }

    &--primary {
      background-color: $mb-color-primary;
    }

    &--outline {
      background-color: transparent;
      border: 2rpx solid $mb-color-border;
    }
  }

  &__btn-text {
    font-size: 30rpx;
    font-weight: 600;
    letter-spacing: 0.1em;

    &--primary {
      color: $mb-color-text-inverse;
    }

    &--outline {
      color: $mb-color-text;
    }
  }
}
</style>
