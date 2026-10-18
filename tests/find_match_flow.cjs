/* Runs against a disposable application from find_match_flow_integration.py. */
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
 const browser = await chromium.launch({headless:true, ...(process.env.CHROMIUM_PATH ? {executablePath:process.env.CHROMIUM_PATH}:{}), args:['--no-sandbox','--use-gl=angle','--use-angle=swiftshader','--single-process']});
 try {
  const context = await browser.newContext();
  const base = process.env.FLOW_TEST_URL;
  await context.addCookies([{name:'PHPSESSID', value:'testflow1', url:base}]);
  const page = await context.newPage();
  const errors = []; page.on('pageerror', exception => errors.push(String(exception)));
  const saved = []; page.on('request', request => { if(request.url().endsWith('/api/find_match.php') && request.method()==='POST') saved.push(request.postDataJSON().answers); });
  const active = () => page.locator('.fm-flow-question:visible');
  const next = () => page.locator('[data-match-next]');
  const back = () => page.locator('[data-match-back]');
  const key = () => active().getAttribute('data-flow-question');
  async function at(expected) {
   await page.waitForFunction(value => document.querySelector('.fm-flow-question:not([hidden])')?.dataset.flowQuestion === value, expected);
   assert.equal(await active().count(),1); assert.equal(await key(),expected);
  }
  async function choose(value) {
   await active().locator('input[value='+JSON.stringify(value)+']').locator('..').click();
  }
  async function shot(name) {
   if(process.env.FLOW_SCREENSHOTS) await page.screenshot({path:process.env.FLOW_SCREENSHOTS+'/'+name+'.png',fullPage:true});
  }
  for(const width of [1440,1024,750,390,320]) {
   await page.setViewportSize({width,height:900});
   const response=await page.goto(base+'/matches.php?edit=1'); assert.equal(response.status(),200);
   await at('intent');
   await page.waitForFunction(()=>Array.from(document.querySelectorAll('link[rel=stylesheet]')).some(link=>link.href.includes('find-match-flow.css')&&link.sheet));
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Opening overflow '+width);
   assert.equal(await page.locator('.fm-detail-offer').count(),0);
   if([1440,390].includes(width))await shot('questionnaire-opening-'+width);
  }
  await page.setViewportSize({width:390,height:844});
  await next().click(); assert.match(await page.locator('[data-match-error]').textContent(),/Choose an answer/); await at('intent');
  await choose('Casual dating'); assert.equal(await key(),'intent');
  await active().locator('input[value="Casual dating"]').focus();
  await page.keyboard.press('ArrowDown'); assert.equal(await key(),'intent');
  await choose('Casual dating');
  await next().click(); await at('connection_pace');
  assert.equal(await page.getByRole('heading',{name:'What kind of connection feels right?'}).isVisible(),false);
  assert(await active().locator('input[value="Taking my time"]').isChecked());
  await back().click(); await at('intent');assert(await active().locator('input[value="Casual dating"]').isChecked());
  await next().click(); await at('connection_pace');
  await page.route('**/api/find_match.php',async route=>{
   await new Promise(resolve=>setTimeout(resolve,150));
   await route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({error:'Test save unavailable. Try again.'})});
  },{times:1});
  await page.locator('[data-match-skip]').click();
  await page.waitForFunction(()=>document.querySelector('[data-match-error]').textContent.includes('Test save unavailable'));
  await at('connection_pace');assert.equal(await next().isDisabled(),false);
  await page.locator('[data-match-skip]').click(); await at('social_activity');
  await choose('Quiet moments');await next().click();await at('communication_style');
  assert.equal(await page.getByRole('heading',{name:'What feels most like you?'}).isVisible(),false);
  await back().click();await at('social_activity');assert(await active().locator('input[value="Quiet moments"]').isChecked());
  await next().click();await at('communication_style');await choose('A bit of both');await next().click();await at('hangout_style');
  for(const width of [1440,390,320]) {
   await page.setViewportSize({width,height:844});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   if([1440,390].includes(width))await shot('questionnaire-vibe-extra-'+width);
  }
  await page.setViewportSize({width:390,height:844});
  await choose('Small groups');await next().click();await at('conversation_opener');
  await page.locator('[data-match-skip]').click();await at('interests');
  const interests=await active().locator('input').evaluateAll(inputs=>inputs.map(input=>input.value));
  for(const value of interests)await choose(value);
  await next().click();await at('interests');assert.match(await page.locator('[data-match-error]').textContent(),/120/);
  for(const value of interests.filter(value=>!['Music','Sport'].includes(value)))await choose(value);
  await next().click();await at('sports');
  await page.locator('[data-match-skip-rest]').click();await at('availability');
  const skipped=saved.find(answers=>answers.interests==='Music, Sport');assert(skipped);assert.equal(skipped.sports,'');assert.equal(skipped.music_styles,'');
  await back().click();await at('sports');
  await choose('Football');await choose('Basketball');await next().click();await at('sport_style');
  await choose('Watching');await next().click();await at('football_team');
  await active().locator('input[type=text]').fill('Dynamos');await next().click();await at('music_styles');
  await choose('Amapiano');await next().click();await at('availability');
  const sportSave=saved.find(answers=>answers.football_team==='Dynamos');assert(sportSave);assert.equal(sportSave.sports,'Football, Basketball');
  for(let index=0;await key()!=='interests' && index<10;index++)await back().click();
  await at('interests');assert(await active().locator('input[value="Music"]').isChecked());
  await choose('Sport');await next().click();await at('music_styles');
  assert.equal(await page.locator('[data-flow-question="football_team"]').isVisible(),false);
  assert(await active().locator('input[value="Amapiano"]').isChecked());
  await next().click();await at('availability');await choose('Weekends');await next().click();await at('planning_style');
  await page.locator('[data-match-skip]').click();await page.waitForURL(base+'/matches.php');
  assert.equal(await page.locator('[data-find-match]').count(),0);
  assert.equal(await page.locator('[data-fm-map]').count(),1);
  await page.goto(base+'/matches.php?edit=1');await at('intent');
  assert(await active().locator('input[value="Casual dating"]').isChecked());
  const state=await page.locator('input[data-answer-key]').evaluateAll(inputs=>inputs.filter(input=>input.checked).map(input=>[input.dataset.answerKey,input.value]));
  assert(state.some(([key,value])=>key==='hangout_style'&&value==='Small groups'));
  assert(!state.some(([key])=>['sports','sport_style','planning_style','connection_pace','conversation_opener'].includes(key)));
  assert.deepEqual(errors,[]);
  console.log('PASS: one visible question, all four phases, optional Skip/Skip remaining, nested sports branches, Back, input limits, failed-save retry, keyboard-safe selection, reload/prefill and desktop/mobile layouts.');
 } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exit(1)});
