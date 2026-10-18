/* Optional Playwright checks, run by admin_content_integration.py.
   PLAYWRIGHT_MODULE and CHROMIUM_PATH may select existing browser tooling. */
const assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_PATH?{executablePath:process.env.CHROMIUM_PATH}:{}),args:['--no-sandbox','--use-gl=angle','--use-angle=swiftshader','--single-process']});
 try{
  const context=await browser.newContext();const base=process.env.CONTENT_TEST_URL;
  await context.addCookies([{name:'PHPSESSID',value:'testcontent1',url:base}]);
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(String(e)));
  for(const width of [1440,1024,750,390,320]){
   await page.setViewportSize({width,height:900});
   for(const [route,title,q] of [['confessions','Confessions management','?q=%231'],['moderation','Moderation centre','?q=%232']]){
    const response=await page.goto(base+'/admin/'+route+'.php'+q);assert.equal(response.status(),200);
    await page.evaluate(()=>document.fonts.ready);assert.equal(await page.locator('h1').textContent(),title);
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${route} overflow ${width}`);
    assert(await page.locator('.admin-nav a[aria-current]').evaluate((a,name)=>a.textContent.trim()===name,route==='confessions'?'Confessions':'Moderation'));
    for(const asset of ['users-desktop-halo-top.svg','users-desktop-halo-bottom.svg','users-mobile-halo-top.svg','users-mobile-halo-bottom.svg']){
     const result=await context.request.get(base+'/assets/admin/'+asset);assert.equal(result.status(),200);assert((await result.body()).length>0);
    }
    if(width<=750){
     assert.equal(await page.locator('.admin-sidebar').isVisible(),false);
     assert(await page.locator('.reports-bottom-nav').isVisible());
     assert(await page.evaluate(()=>document.querySelector('.reports-bottom-nav').getBoundingClientRect().top>=document.querySelector('main').getBoundingClientRect().bottom));
    }
    if(process.env.CONTENT_SCREENSHOTS&&[1440,390].includes(width))await page.screenshot({path:process.env.CONTENT_SCREENSHOTS+'/'+route+'-'+width+'.png',fullPage:true});
   }
  }
  for(const width of [1440,390,320]){
   await page.setViewportSize({width,height:844});const response=await page.goto(base+'/admin/users.php');assert.equal(response.status(),200);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   if(width<=750){
    assert.equal(await page.locator('.admin-users-bottom-nav a').count(),5);
    assert(await page.evaluate(()=>document.querySelector('.admin-users-bottom-nav').getBoundingClientRect().top>=document.querySelector('main').getBoundingClientRect().bottom));
    assert.equal(await page.locator('.admin-users-bottom-nav a').filter({hasText:'Confessions'}).getAttribute('href'),'confessions.php');
   }
  }
  await page.setViewportSize({width:390,height:844});await page.goto(base+'/admin/confessions.php');
  await page.getByLabel('Search confessions',{exact:true}).fill('#1');await page.getByRole('button',{name:'Search',exact:true}).click();
  assert(page.url().endsWith('#content-filters'));assert(await page.evaluate(()=>scrollY>200));
  assert.equal(await page.locator('.report-row').count(),1);await page.getByRole('link',{name:'View post #1 details'}).click();
  assert(page.url().endsWith('#content-detail'));assert.equal(await page.locator('input[name=confirm]').isChecked(),false);
  assert.equal(await page.locator('input[name=confirm]').evaluate(e=>e.checkValidity()),false);
  assert(await page.getByRole('link',{name:'Report #1'}).isVisible());
  await page.goto(base+'/admin/moderation.php?view=suspended');await page.getByRole('link',{name:'Review account #5'}).click();
  assert(await page.getByRole('button',{name:'Restore account #5'}).isVisible());
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.goto(base+'/admin/reports.php?report=5#report-detail');const link=page.getByRole('link',{name:'View evidence image'});
  assert(await link.isVisible());const [popup]=await Promise.all([page.waitForEvent('popup'),link.click()]);
  await popup.waitForLoadState();assert(popup.url().includes('report-evidence.php?report=5'));await popup.close();
  assert.deepEqual(errors,[]);console.log('PASS: Confessions/Moderation desktop and mobile, assets, active/bottom navigation, search scroll, details, confirmations and protected image viewing.');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exit(1)});
