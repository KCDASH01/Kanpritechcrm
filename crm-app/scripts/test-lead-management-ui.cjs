const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const appRoot = path.resolve(__dirname, '..');
const repositoryRoot = path.resolve(appRoot, '..');

const read = (...segments) => fs.readFileSync(path.join(...segments), 'utf8');
const leadRoute = read(appRoot, 'src', 'app', '(dashboard)', 'leads', 'page.tsx');
const leadsWorkspace = read(appRoot, 'src', 'components', 'leads', 'LeadsWorkspace.tsx');
const teamPage = read(appRoot, 'src', 'app', '(dashboard)', 'team', 'page.tsx');
const leadStatuses = read(appRoot, 'src', 'lib', 'leadStatuses.ts');
const leadBadge = read(appRoot, 'src', 'components', 'ui', 'Badge.tsx');
const statusStepper = read(appRoot, 'src', 'components', 'leads', 'StatusStepperCard.tsx');
const leadApi = read(appRoot, 'src', 'lib', 'api', 'leads.ts');
const employeeApi = read(appRoot, 'src', 'lib', 'api', 'employees.ts');
const apiRoutes = read(repositoryRoot, 'crm-api', 'routes', 'api.php');
const releaseRoute = read(appRoot, 'src', 'app', 'api', 'release', 'route.ts');
const reportOverview = read(appRoot, 'src', 'app', '(dashboard)', 'reports', 'page.tsx');
const leadReport = read(appRoot, 'src', 'components', 'reports', 'LeadReportTab.tsx');
const revenueReport = read(appRoot, 'src', 'components', 'reports', 'RevenueReportTab.tsx');
const importantPage = read(appRoot, 'src', 'app', '(dashboard)', 'important', 'page.tsx');

assert.match(leadRoute, /return <LeadsWorkspace \/>/, 'The active /leads route must render LeadsWorkspace.');

assert.match(teamPage, /data-feature-team-lifecycle="v2"/);
assert.match(teamPage, /lifecycleMember &&/);
assert.match(teamPage, />Transfer leads</);
assert.match(teamPage, />Move leads to Unassigned</);
assert.match(teamPage, /employeesApi\.removalPreview/);
assert.doesNotMatch(teamPage, /confirm\(['"]Remove this member\?/);

assert.match(leadStatuses, /'future_lead',/);
assert.match(leadStatuses, /future_lead:\s+'Future Lead'/);
assert.match(leadsWorkspace, /LEAD_STATUS_MENU\.map/);
assert.match(leadsWorkspace, /LEAD_STATUSES\.map/);
assert.match(leadsWorkspace, /set\('status', e\.target\.value\)/);
assert.match(leadReport, /LEAD_STATUSES\.map/);
assert.match(reportOverview, /funnel\.filter\(\(entry\) => entry\.status !== 'future_lead'\)\.reduce/);
assert.match(statusStepper, /key: 'future_lead'/);
assert.match(leadBadge, /future_lead:\s+'bg-teal-100/);

assert.match(leadsWorkspace, /data-feature-lead-management="v2"/);
assert.match(leadsWorkspace, /data-summary-scope=\{individualLeadScope \? 'member' : 'organization'\}/);
assert.match(leadsWorkspace, /individualLeadScope = assignedToFilter !== undefined \|\| unassignedFilter/);
assert.match(leadsWorkspace, /showDepartmentFilters && !individualLeadScope/);
assert.match(leadsWorkspace, /aria-label="Select all leads on this page"/);
assert.match(leadsWorkspace, /aria-label=\{`Select \$\{lead\.full_name\}`\}/);
assert.match(leadsWorkspace, /Bulk Reassign/);
assert.match(leadsWorkspace, /title="Bulk Reassign Leads"/);
assert.match(leadsWorkspace, /assigned_to:\s+assignedToFilter/);
assert.match(leadsWorkspace, /data-bulk-selection=\{isManager \? 'enabled' : 'disabled'\}/);
assert.match(leadsWorkspace, /queryKey: \['lead-catalogue-options'\]/);
assert.match(leadsWorkspace, /<optgroup label="Products">/);
assert.match(leadsWorkspace, /<optgroup label="Services">/);
assert.match(leadsWorkspace, /<optgroup label="Legacy requirements">/);
assert.match(leadsWorkspace, /aria-label="Search products and services"/);
assert.match(leadsWorkspace, /Catalogue unavailable\. Legacy requirements remain available\./);
assert.match(leadsWorkspace, /No active catalogue items\. Legacy requirements remain available\./);

assert.match(leadApi, /\/leads\/bulk-reassign/);
assert.match(leadApi, /\/leads\/catalogue-options/);
assert.match(employeeApi, /\/removal-preview/);
assert.match(apiRoutes, /leads\/bulk-reassign/);
assert.match(apiRoutes, /leads\/catalogue-options/);
assert.match(apiRoutes, /employees\/\{employee\}\/removal-preview/);

for (const source of [leadReport, revenueReport, importantPage]) {
  assert.match(source, /queryKey: \['lead-catalogue-options'\]/);
  assert.match(source, /catalogueOptions\.map/);
}

assert.match(releaseRoute, /lead-management-v2/);
assert.match(releaseRoute, /employee-lead-transfer/);
assert.match(releaseRoute, /bulk-lead-reassignment/);
assert.match(releaseRoute, /employee-lead-summary/);
assert.match(releaseRoute, /anchored-lead-action-menu/);

const buildRoot = path.join(appRoot, '.next');
if (fs.existsSync(path.join(buildRoot, 'BUILD_ID'))) {
  const javascriptFiles = [];
  const visit = (directory) => {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const location = path.join(directory, entry.name);
      if (entry.isDirectory()) visit(location);
      else if (entry.isFile() && entry.name.endsWith('.js')) javascriptFiles.push(location);
    }
  };

  visit(buildRoot);
  const artifact = javascriptFiles.map((file) => fs.readFileSync(file, 'utf8')).join('\n');
  assert.match(artifact, /data-feature-team-lifecycle/);
  assert.match(artifact, /data-feature-lead-management/);
  assert.match(artifact, /Transfer leads/);
  assert.match(artifact, /Move leads to Unassigned/);
  assert.match(artifact, /Bulk Reassign Leads/);
  assert.match(artifact, /lead-management-v2/);
  assert.match(artifact, /Future Lead/);
  console.log('Lead-management compiled-artifact contract: passed');
}

console.log('Lead-management active-route UI contract: passed');
