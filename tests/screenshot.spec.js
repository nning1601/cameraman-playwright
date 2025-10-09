import{test,expect} from '@playwright/test';

test('screenshot', async ({ page }) => {
    await page.goto('https://www.instagram.com/');
    //await page.screenshot({ path: 'screenshot.png' });
    await page.screenshot({ path: 'screenshot.png', fullPage: true });
    await page.getByRole("link",{name:"Forgot password?"}).click();
    await page.screenshot({ path: "test-results/screenshot2.png", fullPage: true });
});

test("kmutnb", async({ page }) =>{
    await page.goto("https://www.chula.ac.th/");
    // await page.getByRole("link",{name:"เข้าสู่เว็บไซต์"}).click();
    await page.screenshot({ path: "test-results/ss1.png"});

    await page.screenshot({ path: "test-results/ss2.png", fullPage: true});

    await page.locator('header').screenshot({ path: "test-results/ss3.png"});

    await page.locator('footer').screenshot({ path: "test-results/ss4.png"});

    await page.locator('.site-main').screenshot({ path: "test-results/ss5.png"});
});


test("kmutnb2", async({ page }) =>{
    await page.goto("https://www.chula.ac.th/");
});

test("faceboot", async({ page }) =>{
 await page.goto('https://www.facebook.com/reg/');
  await page.getByRole('textbox', { name: 'ชื่อ' }).click();
  await page.getByRole('textbox', { name: 'ชื่อ' }).fill('cat');
  await page.getByRole('textbox', { name: 'นามสกุล' }).click();
  await page.getByRole('textbox', { name: 'นามสกุล' }).fill('one');
  await page.getByLabel('วัน').selectOption('17');
  await page.getByLabel('เดือน').selectOption('8');
  await page.getByLabel('ปี').selectOption('2003');
  await page.getByRole('radio', { name: 'ชาย' }).check();
  await page.getByRole('textbox', { name: 'หมายเลขโทรศัพท์มือถือหรืออีเมล' }).click();
  await page.getByRole('textbox', { name: 'หมายเลขโทรศัพท์มือถือหรืออีเมล' }).fill('987654321');
  await page.getByRole('textbox', { name: 'รหัสผ่านใหม่' }).click();
  await page.getByRole('textbox', { name: 'รหัสผ่านใหม่' }).fill('123456789');
 });  
    