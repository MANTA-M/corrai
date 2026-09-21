import { test, expect } from '@playwright/test'
import { initProfile } from './helpers/profile'
import path from 'path'
import fs from 'fs'
import os from 'os'

test.describe('Exams CRUD', () => {
  test('list, create, edit, upload file, and delete an exam', async ({ page }) => {
    await test.step('List shows empty state after profile init', async () => {
      await initProfile(page, 'Exam Tester')
      await expect(page.getByTestId('exams-heading')).toHaveText('Exams')
      await expect(page.getByTestId('exams-empty')).toBeVisible({ timeout: 15000 })
      await expect(page.getByTestId('exams-empty')).toHaveText('No exams yet.')

      const session = await page.evaluate(() => {
        const raw = localStorage.getItem('corrai-session')
        return raw ? JSON.parse(raw) : null
      })
      expect(session?.user_id).toBeTruthy()
      expect(typeof session.user_id).toBe('string')
      expect(session.user_id.length).toBe(7)
      // Exam list must not be persisted in localStorage (S3 is source of truth)
      expect(session.own_exams).toBeUndefined()
    })

    await test.step('Create an exam', async () => {
      await page.getByTestId('exam-create-button').click()
      await page.waitForURL('**/create_exam', { timeout: 10000 })

      await page.getByTestId('exam-name').fill('Math Midterm')
      await page.getByTestId('exam-subject').fill('Mathematics')
      await page.getByTestId('exam-date').fill('2026-10-15')
      await page.getByTestId('exam-submit').click()

      await page.waitForURL(/\/exam\/[^/]+$/, { timeout: 15000 })
      await expect(page.getByTestId('exam-details-heading')).toContainText('Math Midterm')
      await expect(page.getByTestId('exam-name-value')).toHaveText('Math Midterm')
      await expect(page.getByTestId('exam-subject-value')).toHaveText('Mathematics')
      await expect(page.getByTestId('exam-date-value')).toHaveText('2026-10-15')
      await expect(page.getByTestId('exam-save')).toHaveCount(0)
      await expect(page.getByTestId('exam-name')).toHaveCount(0)
      await expect(page.getByTestId('exam-files-empty')).toBeVisible()
      await expect(page.getByTestId('files-view-type')).toHaveCount(0)
      await expect(page.getByTestId('files-view-author')).toHaveCount(0)
      await expect(page.getByTestId('file-type-zones')).toHaveCount(0)
      await expect(page.getByTestId('file-author-view')).toHaveCount(0)

      await page.goto('/exam-list')
      await expect(page.getByTestId('exam-item')).toHaveCount(1)
      await expect(page.getByTestId('exam-item')).toContainText('Math Midterm')
      await expect(page.getByTestId('exam-item')).toContainText('Mathematics')
      await expect(page.getByTestId('exam-item')).toContainText('2026-10-15')

      // After create, localStorage still must not hold exam records
      const sessionAfterCreate = await page.evaluate(() => {
        const raw = localStorage.getItem('corrai-session')
        return raw ? JSON.parse(raw) : null
      })
      expect(sessionAfterCreate?.own_exams).toBeUndefined()
      expect(sessionAfterCreate?.user_id).toBeTruthy()

      // Reload: exams come from S3 via GET /exams, not localStorage
      await page.reload()
      await expect(page.getByTestId('exam-item')).toHaveCount(1, { timeout: 15000 })
      await expect(page.getByTestId('exam-item')).toContainText('Math Midterm')
    })

    await test.step('Edit the exam via Edit button', async () => {
      await page.getByTestId('exam-item').click()
      await page.waitForURL(/\/exam\/[^/]+$/, { timeout: 10000 })

      await page.getByTestId('exam-edit').click()
      await page.waitForURL(/\/exam\/[^/]+\/edit$/, { timeout: 10000 })

      await page.getByTestId('exam-name').fill('Math Final')
      await page.getByTestId('exam-subject').fill('Algebra')
      await page.getByTestId('exam-date').fill('2026-12-01')
      await page.getByTestId('exam-save').click()

      await page.waitForURL(/\/exam\/[^/]+$/, { timeout: 15000 })
      await expect(page.getByTestId('exam-details-heading')).toContainText('Math Final')
      await expect(page.getByTestId('exam-name-value')).toHaveText('Math Final')
      await expect(page.getByTestId('exam-subject-value')).toHaveText('Algebra')
      await expect(page.getByTestId('exam-date-value')).toHaveText('2026-12-01')

      await page.reload()
      await expect(page.getByTestId('exam-name-value')).toHaveText('Math Final')
      await expect(page.getByTestId('exam-subject-value')).toHaveText('Algebra')
      await expect(page.getByTestId('exam-date-value')).toHaveText('2026-12-01')

      await page.goto('/exam-list')
      await expect(page.getByTestId('exam-item')).toContainText('Math Final')
      await expect(page.getByTestId('exam-item')).toContainText('Algebra')
      await expect(page.getByTestId('exam-item')).toContainText('2026-12-01')
    })

    await test.step('Upload a file to the exam', async () => {
      await page.getByTestId('exam-item').click()
      await page.waitForURL(/\/exam\/[^/]+$/, { timeout: 10000 })

      const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'corrai-exam-'))
      const uploadPath = path.join(tmpDir, 'sample-scan.txt')
      fs.writeFileSync(uploadPath, 'Sample exam scan content')

      await page.getByTestId('exam-add-file').click()
      await expect(page.getByTestId('add-file-popup')).toBeVisible()
      await page.getByTestId('add-file-input').setInputFiles(uploadPath)

      await expect(page.getByTestId('exam-file-item')).toContainText('sample-scan.txt', {
        timeout: 15000
      })
      await expect(page.getByTestId('exam-files-empty')).toHaveCount(0)
      await expect(page.getByTestId('files-view-type')).toBeVisible()
      await expect(page.getByTestId('files-view-author')).toBeVisible()
      await expect(page.getByTestId('file-type-zones')).toBeVisible()

      await page.getByTestId('add-file-cancel').click()
      await expect(page.getByTestId('add-file-popup')).toHaveCount(0)
      await expect(page.getByTestId('exam-file-item')).toContainText('sample-scan.txt')

      fs.rmSync(tmpDir, { recursive: true, force: true })
    })

    await test.step('Group files by type and author', async () => {
      await expect(page.getByTestId('file-zone-unknown').getByTestId('exam-file-item')).toContainText(
        'sample-scan.txt'
      )
      await expect(page.getByTestId('file-zone-subject')).toBeVisible()
      await expect(page.getByTestId('file-zone-solution')).toBeVisible()
      await expect(page.getByTestId('file-zone-submission')).toBeVisible()
      await expect(page.getByTestId('file-zone-instructions')).toBeVisible()

      await page.getByTestId('exam-file-item').click()
      await expect(page.getByTestId('file-menu')).toBeVisible()
      await page.getByTestId('file-menu-change-type').click()
      await page.getByTestId('file-menu-type-subject').click()

      await expect(
        page.getByTestId('file-zone-subject').getByTestId('exam-file-item')
      ).toContainText('sample-scan.txt', { timeout: 15000 })
      await expect(
        page.getByTestId('file-zone-unknown').getByTestId('exam-file-item')
      ).toHaveCount(0)

      await page.getByTestId('exam-file-item').click()
      await page.getByTestId('file-menu-set-author').click()
      await page.getByTestId('file-author-input').fill('Alice')
      await page.getByTestId('file-author-save').click()
      await expect(page.getByTestId('file-menu')).toHaveCount(0)

      await page.getByTestId('files-view-author').click()
      await expect(page.getByTestId('file-author-item')).toContainText('Alice')
      await page.getByTestId('file-author-item').click()
      await expect(page.getByTestId('exam-file-item')).toContainText('sample-scan.txt')
      await page.getByTestId('file-author-back').click()
      await expect(page.getByTestId('file-author-item')).toContainText('Alice')

      await page.getByTestId('files-view-type').click()
      await expect(page.getByTestId('file-type-zones')).toBeVisible()
    })

    await test.step('View, rename, and delete a file', async () => {
      await page.getByTestId('exam-file-item').click()
      const viewLink = page.getByTestId('file-menu-view')
      await expect(viewLink).toHaveAttribute('href', /\/file\?/)
      await expect(viewLink).toHaveAttribute('target', '_blank')

      const popupPromise = page.waitForEvent('popup')
      await viewLink.click()
      const popup = await popupPromise
      await popup.waitForLoadState()
      expect(popup.url()).toMatch(/\/file\?/)
      await expect(popup.locator('body')).toContainText('Sample exam scan content')
      await popup.close()

      await page.getByTestId('exam-file-item').click()
      await page.getByTestId('file-menu-rename').click()
      await expect(page.getByTestId('rename-file-popup')).toBeVisible()
      await expect(page.getByTestId('rename-file-input')).toHaveValue('sample-scan.txt')
      await page.getByTestId('rename-file-input').fill('renamed-scan.txt')
      await page.getByTestId('rename-file-save').click()
      await expect(page.getByTestId('rename-file-popup')).toHaveCount(0, { timeout: 15000 })
      await expect(page.getByTestId('exam-file-item')).toContainText('renamed-scan.txt')

      await page.getByTestId('exam-file-item').click()
      await page.getByTestId('file-menu-delete').click()
      await expect(page.getByTestId('delete-file-popup')).toBeVisible()
      await expect(page.getByTestId('delete-file-confirm')).toContainText('renamed-scan.txt')
      await page.getByTestId('delete-file-cancel').click()
      await expect(page.getByTestId('delete-file-popup')).toHaveCount(0)
      await expect(page.getByTestId('exam-file-item')).toContainText('renamed-scan.txt')

      await page.getByTestId('exam-file-item').click()
      await page.getByTestId('file-menu-delete').click()
      await page.getByTestId('delete-file-confirm-button').click()
      await expect(page.getByTestId('exam-file-item')).toHaveCount(0, { timeout: 15000 })
      await expect(page.getByTestId('exam-files-empty')).toBeVisible()
      await expect(page.getByTestId('files-view-type')).toHaveCount(0)
      await expect(page.getByTestId('files-view-author')).toHaveCount(0)
      await expect(page.getByTestId('file-type-zones')).toHaveCount(0)
      await expect(page.getByTestId('file-author-view')).toHaveCount(0)
    })

    await test.step('Delete the exam', async () => {
      page.once('dialog', async (dialog) => {
        expect(dialog.message()).toContain('Are you sure')
        await dialog.accept()
      })

      await page.getByTestId('exam-delete').click()
      await page.waitForURL('**/exam-list', { timeout: 15000 })
      await expect(page.getByTestId('exams-empty')).toBeVisible()
      await expect(page.getByTestId('exam-item')).toHaveCount(0)
    })
  })

  test('migrates a legacy public-key session to a server user id', async ({ page }) => {
    const publicKey =
      'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEO2M68bVhh9hununNXtZsqFnJeMjxIC+Vk5l7ncoMlXCrfFxkMp6OFEffvL/aiOyL1ND+rJZY+sfvEM1qaxNPug=='

    const examAuthHeaders: string[] = []
    page.on('request', (request) => {
      if (request.url().includes('/api/exams') && request.method() === 'GET') {
        examAuthHeaders.push(request.headers()['authorization'] ?? '')
      }
    })

    await page.addInitScript((key) => {
      localStorage.setItem(
        'corrai-session',
        JSON.stringify({
          user_name: 'Legacy User',
          locale: 'en',
          keyPair: { publicKey: key, privateKey: 'legacy-private-key' },
          user_id: key,
        })
      )
    }, publicKey)

    await page.goto('/exam-list')
    await expect(page.getByTestId('exams-heading')).toHaveText('Exams', { timeout: 20000 })
    await expect(page.getByTestId('exams-empty')).toBeVisible({ timeout: 15000 })

    const session = await page.evaluate(() => {
      const raw = localStorage.getItem('corrai-session')
      return raw ? JSON.parse(raw) : null
    })
    expect(session?.user_id).toMatch(/^[0-9a-zA-Z]{7}$/)
    expect(session.user_id).not.toBe(publicKey)

    expect(examAuthHeaders.length).toBeGreaterThan(0)
    for (const header of examAuthHeaders) {
      expect(header).toMatch(/^Bearer [0-9a-zA-Z]{7}$/)
    }
  })
})
