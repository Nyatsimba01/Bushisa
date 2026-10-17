/* Optional: BROWSER_TEST=tests/reports_layout.cjs python tests/reports_management_integration.py
   Requires Playwright. PLAYWRIGHT_MODULE/CHROMIUM_PATH select existing runtimes. */
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const browser = await chromium.launch({headless:true, ...(process.env.CHROMIUM_PATH ? {executablePath:process.env.CHROMIUM_PATH}:{}), args:['--no-sandbox','--use-gl=angle','--use-angle=swiftshader','--single-process']});
  try {
    const context=await browser.newContext();
    const url=process.env.REPORTS_TEST_URL;
    await context.addCookies([{name:'PHPSESSID',value:'testreports1',url}]);
    const page=await context.newPage();
    const errors=[];page.on('pageerror', e=>errors.push(String(e)));
    for (const width of [1440,1024,750,390,320]) {
      await page.setViewportSize({width,height:900});
      await page.goto(url+'?status=reviewed');await page.evaluate(()=>document.fonts.ready);
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`Overflow at ${width}`);
      assert.equal(await page.locator('.metric h2').first().evaluate(e=>getComputedStyle(e).fontSize),width<=750?'11px':'12px');
      if(width<=750){
        assert.equal(await page.locator('.admin-sidebar').isVisible(),false);
        assert(await page.locator('.reports-bottom-nav').isVisible());
        assert(await page.evaluate(()=>document.querySelector('.reports-bottom-nav').getBoundingClientRect().top >= document.querySelector('main').getBoundingClientRect().bottom));
      }
      if(process.env.REPORTS_SCREENSHOTS && [1440,390].includes(width)) await page.screenshot({path:process.env.REPORTS_SCREENSHOTS+`/reports-${width}.png`,fullPage:true});
    }
    await page.setViewportSize({width:390,height:844});await page.goto(url);
    await page.getByLabel('Search reports',{exact:true}).fill('#1');await page.getByRole('button',{name:'Search',exact:true}).click();
    assert(page.url().endsWith('#report-filters'));
    assert(await page.evaluate(()=>scrollY>200));
    assert.equal(await page.locator('.report-row').count(),1);
    await page.getByRole('link',{name:'Open report #1 details'}).click();
    await page.locator('#report-detail').waitFor();
    assert(page.url().endsWith('#report-detail'));
    assert.equal(await page.locator('.confirmation input').isChecked(),false);
    assert.equal(await page.locator('.confirmation input').evaluate(e=>e.checkValidity()),false);
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    if(process.env.REPORTS_SCREENSHOTS)await page.screenshot({path:process.env.REPORTS_SCREENSHOTS+'/reports-detail-mobile.png',fullPage:true});
    assert.deepEqual(errors,[]);
    console.log('PASS: desktop/mobile widths, bottom navigation, no overflow, search scroll, detail navigation, confirmation validation.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
