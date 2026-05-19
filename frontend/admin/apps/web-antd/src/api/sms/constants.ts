/**
 * 短信模块前端常量
 *
 * 与后端 SmsProvider::DRIVER_* / SmsSign::AUDIT_* / SmsTemplate::AUDIT_* 保持一致。
 * 通过常量和 helper 避免在各页面硬编码 driver 字符串("aliyun_pnvs"),
 * 后端如有新增不支持远端签名/模板管理的驱动,只需在此扩展。
 */

export const SMS_DRIVER = {
  ALIYUN: 'aliyun',
  ALIYUN_PNVS: 'aliyun_pnvs',
  TENCENT: 'tencent',
  MOCK: 'mock',
} as const;

export type SmsDriverCode = (typeof SMS_DRIVER)[keyof typeof SMS_DRIVER];

export const SMS_AUDIT_STATUS = {
  PENDING: 'pending',
  PASSED: 'passed',
  REJECTED: 'rejected',
  LOCAL_ONLY: 'local_only',
} as const;

export type SmsAuditStatus = (typeof SMS_AUDIT_STATUS)[keyof typeof SMS_AUDIT_STATUS];

/**
 * 判断驱动是否为 PNVS(阿里云号码认证服务)
 *
 * PNVS 的签名/模板由阿里云预置,本地仅作引用登记,不走审核流程。
 * 用于:
 *  - 创建弹窗动态显隐字段(签名来源/类型/资质文件、模板编码、模板类型/内容)
 *  - 列表行"同步状态"等按钮的可见性
 *  - 场景绑定时的状态过滤
 */
export function isPnvsDriver(driver?: string | null): boolean {
  return driver === SMS_DRIVER.ALIYUN_PNVS;
}
