// @feature 2fa
export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
// @end-feature
// @feature !2fa
export {};
// @end-feature
