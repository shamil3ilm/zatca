// Generated from docs/openapi.yaml by `php artisan masaar:sdk-types`.
// Do not edit: changes are overwritten and SdkTypesDriftTest fails.
//
// The hand-written client imports from here so the endpoint surface it
// exposes is the one the API actually serves.

export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Success<T = unknown> {
  success: true;
  message?: string;
  data?: T;
}

export interface Paginated<T = unknown> {
  success: true;
  message?: string;
  data: T[];
  meta: PageMeta;
}

export interface ApiError {
  success: false;
  error: {
    message: string;
    code: string;
    details?: unknown;
    category?: string;
  };
}

export type ApiResult<T = unknown> = Success<T> | Paginated<T> | ApiError;

export interface AuthLoginBody {
  email: string;
  password: string;
}

export interface AuthRegisterBody {
  name: string;
  email: string;
  password: string;
}

export interface InvoiceStoreBody {
  invoice_number: string;
  type: string;
  document_type?: string;
  issue_date: string;
  supply_date?: string;
  currency?: string;
  exchange_rate?: number;
  payment_means_code?: string;
  buyer_name: string;
  buyer_vat_number?: string;
  buyer_id?: string;
  buyer_id_scheme?: string;
  buyer_address?: unknown[];
  billing_ref?: string;
  adjustment_reason?: string;
  discount_amount?: number;
  notes?: string;
  lines: unknown[];
}

export interface InvoiceUpdateBody {
  invoice_number: string;
  type: string;
  document_type?: string;
  issue_date: string;
  supply_date?: string;
  currency?: string;
  exchange_rate?: number;
  payment_means_code?: string;
  buyer_name: string;
  buyer_vat_number?: string;
  buyer_id?: string;
  buyer_id_scheme?: string;
  buyer_address?: unknown[];
  billing_ref?: string;
  adjustment_reason?: string;
  discount_amount?: number;
  notes?: string;
  lines: unknown[];
}

export interface InvoiceUpdateBody {
  invoice_number: string;
  type: string;
  document_type?: string;
  issue_date: string;
  supply_date?: string;
  currency?: string;
  exchange_rate?: number;
  payment_means_code?: string;
  buyer_name: string;
  buyer_vat_number?: string;
  buyer_id?: string;
  buyer_id_scheme?: string;
  buyer_address?: unknown[];
  billing_ref?: string;
  adjustment_reason?: string;
  discount_amount?: number;
  notes?: string;
  lines: unknown[];
}

export interface V1InvoiceControllerBody {
  invoice_number: string;
  type: string;
  document_type?: string;
  issue_date: string;
  supply_date?: string;
  currency?: string;
  exchange_rate?: number;
  payment_means_code?: string;
  buyer_name: string;
  buyer_vat_number?: string;
  buyer_id?: string;
  buyer_id_scheme?: string;
  buyer_address?: unknown[];
  billing_ref?: string;
  adjustment_reason?: string;
  discount_amount?: number;
  notes?: string;
  lines: unknown[];
}

export interface V1InvoiceControllerBody {
  invoice_number: string;
  type: string;
  document_type?: string;
  issue_date: string;
  supply_date?: string;
  currency?: string;
  exchange_rate?: number;
  payment_means_code?: string;
  buyer_name: string;
  buyer_vat_number?: string;
  buyer_id?: string;
  buyer_id_scheme?: string;
  buyer_address?: unknown[];
  billing_ref?: string;
  adjustment_reason?: string;
  discount_amount?: number;
  notes?: string;
  lines: unknown[];
}

export interface V1PipelineControllerBody {
  invoice_number?: string;
  type: string;
  document_type?: string;
  issue_date: string;
  supply_date?: string;
  currency?: string;
  exchange_rate?: number;
  payment_means_code?: string;
  buyer_name: string;
  buyer_vat_number?: string;
  buyer_id?: string;
  buyer_id_scheme?: string;
  buyer_address?: unknown[];
  billing_ref?: string;
  adjustment_reason?: string;
  discount_amount?: number;
  notes?: string;
  lines: unknown[];
  invoice_type?: string;
  due_date?: string;
  org_id: string;
  auto_submit?: boolean;
  branch_id?: string;
  erp_reference_id?: string;
}

export type Security = 'bearerAuth' | 'apiKey' | 'metricsToken';

export interface Operation {
  method: 'get' | 'post' | 'put' | 'patch' | 'delete';
  path: string;
  security: Security[];
  scopes: string[];
}

export const operations = {
  'adminDashboardController.index': { method: 'get', path: '/api/admin/dashboard', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.resetCircuitBreaker': { method: 'post', path: '/api/admin/dashboard/circuit-breaker/reset', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.connectivity': { method: 'get', path: '/api/admin/dashboard/connectivity', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.refreshConnectivity': { method: 'post', path: '/api/admin/dashboard/connectivity/refresh', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.errorRates': { method: 'get', path: '/api/admin/dashboard/error-rates', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.hashChainHealth': { method: 'get', path: '/api/admin/dashboard/hash-chain-health', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.health': { method: 'get', path: '/api/admin/dashboard/health', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.issues': { method: 'get', path: '/api/admin/dashboard/issues', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.logs': { method: 'get', path: '/api/admin/dashboard/logs', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.offlineQueue': { method: 'get', path: '/api/admin/dashboard/offline-queue', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.processOfflineQueue': { method: 'post', path: '/api/admin/dashboard/offline-queue/process', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.offlineQueueByOrg': { method: 'get', path: '/api/admin/dashboard/offline-queue/{organizationId}', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.retryQueueItem': { method: 'post', path: '/api/admin/dashboard/offline-queue/{queueId}/retry', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.runHealthCheck': { method: 'post', path: '/api/admin/dashboard/run-health-check', security: ['bearerAuth'], scopes: [] },
  'adminDashboardController.topOrganizations': { method: 'get', path: '/api/admin/dashboard/top-organizations', security: ['bearerAuth'], scopes: [] },
  'licenseController.index': { method: 'get', path: '/api/admin/licenses', security: ['bearerAuth'], scopes: [] },
  'licenseController.store': { method: 'post', path: '/api/admin/licenses', security: ['bearerAuth'], scopes: [] },
  'licenseController.cleanup': { method: 'post', path: '/api/admin/licenses/cleanup', security: ['bearerAuth'], scopes: [] },
  'licenseController.statistics': { method: 'get', path: '/api/admin/licenses/statistics', security: ['bearerAuth'], scopes: [] },
  'licenseController.show': { method: 'get', path: '/api/admin/licenses/{id}', security: ['bearerAuth'], scopes: [] },
  'licenseController.activate': { method: 'post', path: '/api/admin/licenses/{id}/activate', security: ['bearerAuth'], scopes: [] },
  'licenseController.audit': { method: 'get', path: '/api/admin/licenses/{id}/audit', security: ['bearerAuth'], scopes: [] },
  'licenseController.extend': { method: 'post', path: '/api/admin/licenses/{id}/extend', security: ['bearerAuth'], scopes: [] },
  'licenseController.updateFeatures': { method: 'patch', path: '/api/admin/licenses/{id}/features', security: ['bearerAuth'], scopes: [] },
  'licenseController.updateLimits': { method: 'patch', path: '/api/admin/licenses/{id}/limits', security: ['bearerAuth'], scopes: [] },
  'licenseController.reactivate': { method: 'post', path: '/api/admin/licenses/{id}/reactivate', security: ['bearerAuth'], scopes: [] },
  'licenseController.regenerateSecret': { method: 'post', path: '/api/admin/licenses/{id}/regenerate-secret', security: ['bearerAuth'], scopes: [] },
  'licenseController.revoke': { method: 'post', path: '/api/admin/licenses/{id}/revoke', security: ['bearerAuth'], scopes: [] },
  'licenseController.suspend': { method: 'post', path: '/api/admin/licenses/{id}/suspend', security: ['bearerAuth'], scopes: [] },
  'licenseController.upgrade': { method: 'post', path: '/api/admin/licenses/{id}/upgrade', security: ['bearerAuth'], scopes: [] },
  'licenseController.usage': { method: 'get', path: '/api/admin/licenses/{id}/usage', security: ['bearerAuth'], scopes: [] },
  'authController.login': { method: 'post', path: '/api/auth/login', security: [], scopes: [] },
  'authController.logout': { method: 'post', path: '/api/auth/logout', security: ['bearerAuth'], scopes: [] },
  'authController.me': { method: 'get', path: '/api/auth/me', security: ['bearerAuth'], scopes: [] },
  'authController.refresh': { method: 'post', path: '/api/auth/refresh', security: ['bearerAuth'], scopes: [] },
  'authController.register': { method: 'post', path: '/api/auth/register', security: [], scopes: [] },
  'ftaController.retry': { method: 'post', path: '/api/compliance/ae/retry/{submissionId}', security: ['bearerAuth'], scopes: [] },
  'ftaController.status': { method: 'get', path: '/api/compliance/ae/status/{submissionId}', security: ['bearerAuth'], scopes: [] },
  'ftaController.index': { method: 'get', path: '/api/compliance/ae/submissions', security: ['bearerAuth'], scopes: [] },
  'ftaController.submit': { method: 'post', path: '/api/compliance/ae/submit/{invoiceId}', security: ['bearerAuth'], scopes: [] },
  'onboardingController.requestCcsid': { method: 'post', path: '/api/compliance/onboarding/ccsid', security: ['bearerAuth'], scopes: [] },
  'onboardingController.runComplianceCheck': { method: 'post', path: '/api/compliance/onboarding/compliance-check', security: ['bearerAuth'], scopes: [] },
  'onboardingController.requestPcsid': { method: 'post', path: '/api/compliance/onboarding/pcsid', security: ['bearerAuth'], scopes: [] },
  'onboardingController.status': { method: 'get', path: '/api/compliance/onboarding/status', security: ['bearerAuth'], scopes: [] },
  'complianceController.generate': { method: 'post', path: '/api/compliance/sa/generate/{invoiceId}', security: ['bearerAuth'], scopes: [] },
  'complianceController.status': { method: 'get', path: '/api/compliance/sa/status/{submissionId}', security: ['bearerAuth'], scopes: [] },
  'complianceController.submit': { method: 'post', path: '/api/compliance/sa/submit/{invoiceId}', security: ['bearerAuth'], scopes: [] },
  'complianceController.validate': { method: 'post', path: '/api/compliance/sa/validate/{invoiceId}', security: ['bearerAuth'], scopes: [] },
  'dashboardController.index': { method: 'get', path: '/api/dashboard', security: ['bearerAuth'], scopes: [] },
  'dashboardController.activity': { method: 'get', path: '/api/dashboard/activity', security: ['bearerAuth'], scopes: [] },
  'dashboardController.health': { method: 'get', path: '/api/dashboard/health', security: ['bearerAuth'], scopes: [] },
  'dashboardController.invoices': { method: 'get', path: '/api/dashboard/invoices', security: ['bearerAuth'], scopes: [] },
  'dashboardController.submissions': { method: 'get', path: '/api/dashboard/submissions', security: ['bearerAuth'], scopes: [] },
  'dashboardController.usage': { method: 'get', path: '/api/dashboard/usage', security: ['bearerAuth'], scopes: [] },
  'getApiHealth': { method: 'get', path: '/api/health', security: [], scopes: [] },
  'invoiceController.index': { method: 'get', path: '/api/invoices', security: ['bearerAuth'], scopes: [] },
  'invoiceController.store': { method: 'post', path: '/api/invoices', security: ['bearerAuth'], scopes: [] },
  'invoiceController.show': { method: 'get', path: '/api/invoices/{invoice}', security: ['bearerAuth'], scopes: [] },
  'invoiceController.update.put': { method: 'put', path: '/api/invoices/{invoice}', security: ['bearerAuth'], scopes: [] },
  'invoiceController.update.patch': { method: 'patch', path: '/api/invoices/{invoice}', security: ['bearerAuth'], scopes: [] },
  'invoiceController.destroy': { method: 'delete', path: '/api/invoices/{invoice}', security: ['bearerAuth'], scopes: [] },
  'licenseUsageController.info': { method: 'get', path: '/api/license', security: ['apiKey'], scopes: [] },
  'licenseUsageController.checkFeature': { method: 'get', path: '/api/license/features/{feature}', security: ['apiKey'], scopes: [] },
  'licenseUsageController.health': { method: 'get', path: '/api/license/health', security: ['apiKey'], scopes: [] },
  'licenseUsageController.quotas': { method: 'get', path: '/api/license/quotas', security: ['apiKey'], scopes: [] },
  'getApiLicenseStatus': { method: 'get', path: '/api/license/status', security: [], scopes: [] },
  'licenseUsageController.usage': { method: 'get', path: '/api/license/usage', security: ['apiKey'], scopes: [] },
  'metricsController.index': { method: 'get', path: '/api/metrics', security: ['metricsToken'], scopes: [] },
  'organizationController.index': { method: 'get', path: '/api/organizations', security: ['bearerAuth'], scopes: [] },
  'organizationController.store': { method: 'post', path: '/api/organizations', security: ['bearerAuth'], scopes: [] },
  'branchController.index': { method: 'get', path: '/api/organizations/branches', security: ['bearerAuth'], scopes: [] },
  'branchController.store': { method: 'post', path: '/api/organizations/branches', security: ['bearerAuth'], scopes: [] },
  'branchController.show': { method: 'get', path: '/api/organizations/branches/{branch}', security: ['bearerAuth'], scopes: [] },
  'branchController.update': { method: 'put', path: '/api/organizations/branches/{branch}', security: ['bearerAuth'], scopes: [] },
  'branchController.destroy': { method: 'delete', path: '/api/organizations/branches/{branch}', security: ['bearerAuth'], scopes: [] },
  'branchController.onboardingStatus': { method: 'get', path: '/api/organizations/branches/{branch}/onboarding-status', security: ['bearerAuth'], scopes: [] },
  'branchOnboardingController.requestCcsid': { method: 'post', path: '/api/organizations/branches/{branch}/onboarding/ccsid', security: ['bearerAuth'], scopes: [] },
  'branchOnboardingController.runComplianceCheck': { method: 'post', path: '/api/organizations/branches/{branch}/onboarding/compliance-check', security: ['bearerAuth'], scopes: [] },
  'branchOnboardingController.requestPcsid': { method: 'post', path: '/api/organizations/branches/{branch}/onboarding/pcsid', security: ['bearerAuth'], scopes: [] },
  'branchOnboardingController.resetOnboarding': { method: 'post', path: '/api/organizations/branches/{branch}/onboarding/reset', security: ['bearerAuth'], scopes: [] },
  'branchController.reactivate': { method: 'post', path: '/api/organizations/branches/{branch}/reactivate', security: ['bearerAuth'], scopes: [] },
  'branchController.setDefault': { method: 'post', path: '/api/organizations/branches/{branch}/set-default', security: ['bearerAuth'], scopes: [] },
  'branchController.suspend': { method: 'post', path: '/api/organizations/branches/{branch}/suspend', security: ['bearerAuth'], scopes: [] },
  'organizationController.switch': { method: 'post', path: '/api/organizations/{id}/switch', security: ['bearerAuth'], scopes: [] },
  'organizationController.show': { method: 'get', path: '/api/organizations/{organization}', security: ['bearerAuth'], scopes: [] },
  'organizationController.update.put': { method: 'put', path: '/api/organizations/{organization}', security: ['bearerAuth'], scopes: [] },
  'organizationController.update.patch': { method: 'patch', path: '/api/organizations/{organization}', security: ['bearerAuth'], scopes: [] },
  'complianceProfileController.index': { method: 'get', path: '/api/organizations/{organization}/compliance-profiles', security: ['bearerAuth'], scopes: [] },
  'complianceProfileController.store': { method: 'post', path: '/api/organizations/{organization}/compliance-profiles', security: ['bearerAuth'], scopes: [] },
  'complianceProfileController.destroy': { method: 'delete', path: '/api/organizations/{organization}/compliance-profiles/{profile}', security: ['bearerAuth'], scopes: [] },
  'v1.complianceController.generate': { method: 'post', path: '/api/v1/compliance/generate/{invoiceId}', security: ['apiKey'], scopes: ['invoice.submit'] },
  'v1.complianceController.status': { method: 'get', path: '/api/v1/compliance/status/{invoiceId}', security: ['apiKey'], scopes: ['compliance.status'] },
  'v1.complianceController.submit': { method: 'post', path: '/api/v1/compliance/submit/{invoiceId}', security: ['apiKey'], scopes: ['invoice.submit'] },
  'v1.complianceController.validate': { method: 'post', path: '/api/v1/compliance/validate/{invoiceId}', security: ['apiKey'], scopes: ['invoice.submit'] },
  'v1.dashboardController.index': { method: 'get', path: '/api/v1/dashboard', security: ['apiKey'], scopes: [] },
  'v1.dashboardController.health': { method: 'get', path: '/api/v1/dashboard/health', security: ['apiKey'], scopes: [] },
  'v1.dashboardController.usage': { method: 'get', path: '/api/v1/dashboard/usage', security: ['apiKey'], scopes: [] },
  'v1.invoiceController.index': { method: 'get', path: '/api/v1/invoices', security: ['apiKey'], scopes: ['invoice.read'] },
  'v1.invoiceController.store': { method: 'post', path: '/api/v1/invoices', security: ['apiKey'], scopes: ['invoice.submit'] },
  'v1.invoiceController.show': { method: 'get', path: '/api/v1/invoices/{invoice}', security: ['apiKey'], scopes: ['invoice.read'] },
  'v1.invoiceController.update': { method: 'put', path: '/api/v1/invoices/{invoice}', security: ['apiKey'], scopes: ['invoice.submit'] },
  'v1.invoiceController.destroy': { method: 'delete', path: '/api/v1/invoices/{invoice}', security: ['apiKey'], scopes: ['invoice.cancel'] },
  'v1.pipelineController.status': { method: 'get', path: '/api/v1/pipeline/status/{invoiceId}', security: ['apiKey'], scopes: ['compliance.status'] },
  'v1.pipelineController.submit': { method: 'post', path: '/api/v1/pipeline/submit', security: ['apiKey'], scopes: ['invoice.submit'] },
  'v1.webhookController.index': { method: 'get', path: '/api/v1/webhooks', security: ['apiKey'], scopes: ['webhook.manage'] },
  'v1.webhookController.store': { method: 'post', path: '/api/v1/webhooks', security: ['apiKey'], scopes: ['webhook.manage'] },
  'v1.webhookController.show': { method: 'get', path: '/api/v1/webhooks/{webhook}', security: ['apiKey'], scopes: ['webhook.manage'] },
  'v1.webhookController.update': { method: 'put', path: '/api/v1/webhooks/{webhook}', security: ['apiKey'], scopes: ['webhook.manage'] },
  'v1.webhookController.destroy': { method: 'delete', path: '/api/v1/webhooks/{webhook}', security: ['apiKey'], scopes: ['webhook.manage'] },
  'webhookController.index': { method: 'get', path: '/api/webhooks', security: ['bearerAuth'], scopes: [] },
  'webhookController.store': { method: 'post', path: '/api/webhooks', security: ['bearerAuth'], scopes: [] },
  'webhookController.events': { method: 'get', path: '/api/webhooks/events', security: ['bearerAuth'], scopes: [] },
  'webhookController.logs': { method: 'get', path: '/api/webhooks/{id}/logs', security: ['bearerAuth'], scopes: [] },
  'webhookController.rotateSecret': { method: 'post', path: '/api/webhooks/{id}/rotate-secret', security: ['bearerAuth'], scopes: [] },
  'webhookController.test': { method: 'post', path: '/api/webhooks/{id}/test', security: ['bearerAuth'], scopes: [] },
  'webhookController.show': { method: 'get', path: '/api/webhooks/{webhook}', security: ['bearerAuth'], scopes: [] },
  'webhookController.update.put': { method: 'put', path: '/api/webhooks/{webhook}', security: ['bearerAuth'], scopes: [] },
  'webhookController.update.patch': { method: 'patch', path: '/api/webhooks/{webhook}', security: ['bearerAuth'], scopes: [] },
  'webhookController.destroy': { method: 'delete', path: '/api/webhooks/{webhook}', security: ['bearerAuth'], scopes: [] },
} as const;

export type OperationId = keyof typeof operations;
