import { test, expect, chromium, BrowserContext, Page } from '@playwright/test';

/**
 * Test scenario:
 * 1. Initialize profile 1
 * 2. Create exam with text
 * 3. Open another browser with another user and initialize user 2
 */

test.describe('Multi-User Exam Scenario', () => {
  let context1: BrowserContext;
  let context2: BrowserContext;
  let page1: Page;
  let page2: Page;
  let browser: any;

  test.beforeAll(async () => {
    // Create two separate browser contexts for two different users
    browser = await chromium.launch();
    context1 = await browser.newContext();
    context2 = await browser.newContext();
    page1 = await context1.newPage();
    page2 = await context2.newPage();
  });

  test.afterAll(async () => {
    await page1.close();
    await page2.close();
    await context1.close();
    await context2.close();
    await browser.close();
  });

  test('Initialize profile 1, create exam with text, then initialize user 2', async () => {
    // Step 1: Initialize Profile 1
    await test.step('Initialize Profile 1', async () => {
      await page1.goto('/');
      
      // Wait for the init profile page to load
      await page1.waitForSelector('text=Créer un nouveau profil', { timeout: 10000 });
      
      // Click "Create New Profile" button
      await page1.click('button:has-text("Créer un nouveau profil")');
      
      // Fill in user name (optional, but we'll add one for testing)
      const userNameInput = page1.locator('input[id="userName"]');
      await userNameInput.fill('User 1');
      
      // Click the create button
      const createButton = page1.locator('button[type="submit"]:has-text("Créer le profil")');
      await createButton.click();
      
      // Wait for navigation to sending list (profile initialized)
      await page1.waitForURL('**/sending-list', { timeout: 10000 });
      
      // Verify we're on the sending list page
      await expect(page1.locator('main h1')).toContainText('Send');
    });

    // Step 2: Create Exam with Text
    await test.step('Create New Exam with Text', async () => {
      // Click "Create Exam" button
      await page1.click('a:has-text("Create Exam")');
      
      // Wait for the exam creation form
      await page1.waitForSelector('h1:has-text("Create New Exam")', { timeout: 10000 });
      
      // Fill in exam name
      const examNameInput = page1.locator('input[id="name"]');
      await examNameInput.fill('Test Exam with Text');
      
      // Select "text" as payload type
      const payloadTypeSelect = page1.locator('select[id="payload_type"]');
      await payloadTypeSelect.selectOption('text');
      
      // Wait a bit for the confidential text field to appear
      await page1.waitForSelector('textarea[id="confidential_text"]', { timeout: 5000 });
      
      // Fill in confidential text
      const confidentialTextArea = page1.locator('textarea[id="confidential_text"]');
      await confidentialTextArea.fill('This is a confidential message for the exam');
      
      // Fill in public instructions (optional)
      const instructionsTextArea = page1.locator('textarea[id="instructions"]');
      await instructionsTextArea.fill('Public instructions for this exam');
      
      // Set expiration date (24 hours from now)
      const expiresOnInput = page1.locator('input[id="expires_on"]');
      const tomorrow = new Date();
      tomorrow.setHours(tomorrow.getHours() + 24);
      const dateTimeString = tomorrow.toISOString().slice(0, 16);
      await expiresOnInput.fill(dateTimeString);
      
      // Submit the form
      const submitButton = page1.locator('button[type="submit"]:has-text("Create Exam")');
      await submitButton.click();
      
      // Wait for the exam to be created and navigate to exam view
      await page1.waitForURL(/\/exam\/[^/]+$/, { timeout: 10000 });
      
      // Verify the exam was created successfully
      await expect(page1.locator('h1')).toContainText('Test Exam with Text');
      
      // Verify the exam type is text
      const prizeValue = page1.locator('.info-row:has(.label:has-text("Prize:")) .value');
      console.log('prizeValue', await prizeValue.textContent());
      await expect(prizeValue).toContainText('text');
    });

    // Step 3: Initialize User 2 in another browser
    await test.step('Initialize User 2 in another browser', async () => {
      // Navigate to the app in the second browser context
      await page2.goto('/');
      
      // Wait for the init profile page to load
      await page2.waitForSelector('text=Créer un nouveau profil', { timeout: 10000 });
      
      // Click "Create New Profile" button
      await page2.click('button:has-text("Créer un nouveau profil")');
      
      // Fill in user name for user 2
      const userNameInput2 = page2.locator('input[id="userName"]');
      await userNameInput2.fill('User 2');
      
      // Click the create button
      const createButton2 = page2.locator('button[type="submit"]:has-text("Créer le profil")');
      await createButton2.click();
      
      // Wait for navigation to sending list (profile initialized)
      await page2.waitForURL('**/sending-list', { timeout: 10000 });
      
      // Verify we're on the sending list page
      await expect(page2.locator('h1')).toContainText('Send');
      
      // Verify user 2 has a different profile (different localStorage)
      // Both users should now be initialized with separate profiles
      const localStorage1 = await page1.evaluate(() => {
        return localStorage.getItem('corrai-session');
      });
      
      const localStorage2 = await page2.evaluate(() => {
        return localStorage.getItem('corrai-session');
      });
      
      // Both should have session data, but with different keys
      expect(localStorage1).toBeTruthy();
      expect(localStorage2).toBeTruthy();
      
      // Parse the session data to verify they have different public keys
      const session1 = JSON.parse(localStorage1 || '{}');
      const session2 = JSON.parse(localStorage2 || '{}');
      
      expect(session1.keyPair?.publicKey).toBeTruthy();
      expect(session2.keyPair?.publicKey).toBeTruthy();
      
      // Verify they have different public keys (different users)
      expect(session1.keyPair.publicKey).not.toBe(session2.keyPair.publicKey);

      // Each profile gets a distinct S3 user id under IND
      expect(session1.user_id).toBeTruthy();
      expect(session2.user_id).toBeTruthy();
      expect(session1.user_id).not.toBe(session2.user_id);
      
      // Verify user names are different
      expect(session1.user_name).toBe('User 1');
      expect(session2.user_name).toBe('User 2');
    });
  });
});
