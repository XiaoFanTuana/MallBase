import { eventHandler } from 'h3';
import { verifyAccessToken } from '~/utils/jwt-utils';
import { MOCK_CODES, MOCK_USERS } from '~/utils/mock-data';
import { unAuthorizedResponse, useResponseSuccess } from '~/utils/response';

export default eventHandler((event) => {
  const userinfo = verifyAccessToken(event);
  if (!userinfo) {
    return unAuthorizedResponse(event);
  }

  // 查找用户的权限码
  const codesData = MOCK_CODES.find(
    (item) => item.username === userinfo.username,
  );

  // 查找完整的用户信息
  const fullUserInfo = MOCK_USERS.find(
    (item) => item.username === userinfo.username,
  );

  return useResponseSuccess({
    ...userinfo,
    ...fullUserInfo,
    permissions: codesData?.codes || [],
  });
});
