/**
 * 应用层用户信息类型定义
 * 如果需要扩展或修改用户信息类型，请在此文件中修改
 */

/** 用户信息 */
export interface AdminInfo {
  id: number;
  username: string;
  nickname: string;
  avatar: string;
  email: string;
  mobile: string;
  status: number;
  realName: string;
  homePath?: string;
  desc?: string;
  token?: string;
  roles?: Array<{
    code: string;
    id: number;
    name: string;
  }>;
  permissions?: string[];
}
