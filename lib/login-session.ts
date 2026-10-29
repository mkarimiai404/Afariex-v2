export type LoginSession = {
  userId: string | null;
  userToken: string;
  userName: string | null;
  userMobile: string | null;
  isSubuser: boolean;
  phoneVerified: boolean;
  kycStatus: string;
};

export class InvalidLoginResponseError extends Error {
  constructor() {
    super('Login response did not contain a valid session.');
    this.name = 'InvalidLoginResponseError';
  }
}

export const parseLoginSession = (response: unknown): LoginSession => {
  const root = response && typeof response === 'object' ? response as Record<string, unknown> : null;
  const data = root?.data && typeof root.data === 'object' ? root.data as Record<string, unknown> : null;
  const token = typeof data?.api_token === 'string' ? data.api_token.trim() : '';
  if (root?.success !== true || root?.status !== 'success' || !data || !token) {
    throw new InvalidLoginResponseError();
  }

  const id = data.id ?? data.user_id;
  const name = data.full_name ?? data.name;
  const userId = id === null || id === undefined ? '' : String(id).trim();
  if (!userId) throw new InvalidLoginResponseError();
  return {
    userToken: token,
    userId,
    userMobile: data.mobile === null || data.mobile === undefined ? null : String(data.mobile),
    userName: name === null || name === undefined || String(name).trim() === '' ? null : String(name),
    isSubuser: data.is_subuser === true || data.is_subuser === 1 || data.is_subuser === '1',
    phoneVerified: data.phone_verified === true || data.phone_verified === 1 || data.phone_verified === '1',
    kycStatus: String(data.kyc_status ?? 'pending'),
  };
};
