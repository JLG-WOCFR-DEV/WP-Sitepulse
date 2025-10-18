import pa11y from 'pa11y';

const targets = [
  {
    url: process.env.SITEPULSE_DASHBOARD_URL || 'http://localhost:8889/wp-admin/admin.php?page=sitepulse-dashboard',
    standard: 'WCAG2AA',
  }
];

(async () => {
  for (const target of targets) {
    console.log(`Running Pa11y against ${target.url}`);
    const results = await pa11y(target.url, {
      standard: target.standard,
      timeout: 30000,
    });

    if (results.issues.length > 0) {
      console.log(`Found ${results.issues.length} accessibility issues:`);
      for (const issue of results.issues) {
        console.log(`- [${issue.type}] ${issue.message} (${issue.selector})`);
      }
      process.exitCode = 1;
    } else {
      console.log('No accessibility issues found by Pa11y.');
    }
  }
})();
