const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const appRoot = path.resolve(__dirname, '..');
const repositoryRoot = path.resolve(appRoot, '..');

const read = (...segments) => fs.readFileSync(path.join(...segments), 'utf8');
const leadRoute = read(appRoot, 'src', 'app', '(dashboard)', 'leads', 'page.tsx');
const leadsWorkspace = read(appRoot, 'src', 'components', 'leads', 'LeadsWorkspace.tsx');
const teamPage = read(appRoot, 'src', 'app', '(dashboard)', 'team', 'page.tsx');
const leadApi = read(appRoot, 'src', 'lib', 'api', 'leads.ts');
const employeeApi = read(appRoot, 'src', 'lib', 'api', 'employees.ts');
const apiRoutes = read(repositoryRoot, 'crm-api', 'routes', 'api.php');
const releaseRoute = read(appRoot, 'src', 'app', 'api', 'release', 'route.ts');

assert.match(leadRoute, /return <LeadsWorkspace \/>/, 'The active /leads route must render LeadsWorkspace.');

assert.match(teamPage, /data-feature-team-lifecycle="v2"/);
assert.match(teamPage, /lifecycleMember &&/);
assert.match(teamPage, />Transfer leads</);
assert.match(teamPage, />Move leads to Unassigned</);
assert.match(teamPage, /employeesApi\.removalPreview/);
assert.doesNotMatch(teamPage, /confirm\(['"]Remove this member\?/);

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

assert.match(leadApi, /\/leads\/bulk-reassign/);
assert.match(employeeApi, /\/removal-preview/);
assert.match(apiRoutes, /leads\/bulk-reassign/);
assert.match(apiRoutes, /employees\/\{employee\}\/removal-preview/);

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
  console.log('Lead-management compiled-artifact contract: passed');
}

console.log('Lead-management active-route UI contract: passed');
