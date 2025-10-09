import { test, expect } from '@playwright/test';

// ชื่อเรื่องการทดสอบ Facebook
test('facebook login test', async ({ page }) => {
  // เปิดหน้า Facebook
  await page.goto('https://www.facebook.com/');

  // กรอกอีเมล/เบอร์โทร
  await page.getByRole('textbox', { name: 'Email address or phone number' }).fill('0631966902');

  // กรอกรหัสผ่าน
  await page.getByRole('textbox', { name: 'Password' }).fill('12345');

  // คลิกปุ่มเข้าสู่ระบบ
  await page.getByRole('button', { name: 'Log in' }).click();

  // ตรวจสอบว่ามี element ที่น่าจะเจอหลังล็อกอิน
  // เช่น ตรวจสอบว่า URL เปลี่ยนหรือมีข้อความ error ขึ้น
  await expect(page).toHaveURL(/facebook\.com/);

  // หรือถ้าใช้ test account สามารถเช็ค element ที่ปรากฏหลังจากล็อกอินสำเร็จ เช่นเมนู Home
  // await expect(page.getByText('Home')).toBeVisible();
});
