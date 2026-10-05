import { test, expect } from '@playwright/test'
import { initProfile } from './helpers/profile'
import path from 'path'
import fs from 'fs'
import os from 'os'

test.describe('Assessments CRUD', () => {
  test('list, create, edit, upload file, and delete an assessment', async ({ page }) => {
    test.setTimeout(60000)
    await test.step('List shows empty state after profile init', async () => {
      await initProfile(page, '[Test] Assessment Tester')
      await expect(page.getByTestId('assessments-heading')).toHaveText('Évaluations')
      await expect(page.getByTestId('assessments-empty')).toBeVisible({ timeout: 15000 })
      await expect(page.getByTestId('assessments-empty')).toHaveText('Aucune évaluation pour le moment.')

      const session = await page.evaluate(() => {
        const raw = localStorage.getItem('corrai-session')
        return raw ? JSON.parse(raw) : null
      })
      expect(session?.user_id).toBeTruthy()
      expect(typeof session.user_id).toBe('string')
      expect(session.user_id.length).toBe(7)
      // Assessment list must not be persisted in localStorage (S3 is source of truth)
      expect(session.own_assessments).toBeUndefined()
    })

    await test.step('Create an assessment', async () => {
      await page.getByTestId('assessment-create-button').click()
      await page.waitForURL('**/create_assessment', { timeout: 10000 })

      await page.getByTestId('assessment-no-subject').click()
      await page.getByTestId('assessment-name').fill('[Test] Math Midterm')
      await page.getByTestId('assessment-subject').selectOption('Math')
      await page.getByTestId('assessment-date').fill('2026-10-15')
      await page.getByTestId('assessment-submit').click()

      await page.waitForURL(/\/assessment\/[^/]+$/, { timeout: 15000 })
      await expect(page.getByTestId('assessment-details-heading')).toContainText('[Test] Math Midterm')
      await expect(page.getByTestId('assessment-meta')).toContainText('2026-10-15, Math')
      await expect(page.getByTestId('assessment-meta')).not.toContainText('—')
      await expect(page.getByTestId('assessment-level-value')).toHaveCount(0)
      await expect(page.getByTestId('assessment-subject-value')).toHaveText('Math')
      await expect(page.getByTestId('assessment-date-value')).toHaveText('2026-10-15')
      await expect(page.getByTestId('assessment-edit')).toHaveCount(0)
      await expect(page.getByTestId('assessment-edit-subject')).toBeVisible()
      await expect(page.getByTestId('assessment-add-file')).toHaveText('Ajouter des copies')
      await expect(page.getByTestId('students-empty')).toBeVisible()
      await expect(page.getByTestId('unassigned-files')).toBeVisible()
      await expect(page.getByTestId('file-type-zones')).toHaveCount(0)
      await expect(page.getByTestId('assessment-save')).toHaveCount(0)
      await expect(page.getByTestId('assessment-name')).toHaveCount(0)

      await page.goto('assessment-list')
      await expect(page.getByTestId('assessment-item')).toHaveCount(1)
      await expect(page.getByTestId('assessment-item')).toContainText('[Test] Math Midterm')
      await expect(page.getByTestId('assessment-item')).toContainText('Math')
      await expect(page.getByTestId('assessment-item')).toContainText('2026-10-15')

      // After create, localStorage still must not hold assessment records
      const sessionAfterCreate = await page.evaluate(() => {
        const raw = localStorage.getItem('corrai-session')
        return raw ? JSON.parse(raw) : null
      })
      expect(sessionAfterCreate?.own_assessments).toBeUndefined()
      expect(sessionAfterCreate?.user_id).toBeTruthy()

      // Reload: assessments come from S3 via GET /assessments, not localStorage
      await page.reload()
      await expect(page.getByTestId('assessment-item')).toHaveCount(1, { timeout: 15000 })
      await expect(page.getByTestId('assessment-item')).toContainText('[Test] Math Midterm')
    })

    await test.step('Edit the assessment from the subject page', async () => {
      await page.getByTestId('assessment-item').click()
      await page.waitForURL(/\/assessment\/[^/]+$/, { timeout: 10000 })
      await expect(page.getByTestId('assessment-edit')).toHaveCount(0)

      await page.getByTestId('assessment-edit-subject').click()
      await page.waitForURL(/\/assessment\/[^/]+\/sujet$/, { timeout: 10000 })

      await page.getByTestId('subject-name-edit').click()
      await page.getByTestId('subject-name-input').fill('[Test] Math Final')
      await page.getByTestId('subject-name-save').click()
      await expect(page.getByTestId('subject-page-title')).toHaveText('Sujet: [Test] Math Final', { timeout: 15000 })

      await page.getByTestId('subject-subject-edit').click()
      await page.getByTestId('subject-subject-input').selectOption('Dictation')
      await page.getByTestId('subject-subject-save').click()
      await expect(page.getByTestId('subject-subject-value')).toHaveText('Français (dictée)')

      await expect(page.getByTestId('subject-country-value')).toHaveText('')
      await expect(page.getByTestId('subject-country-value')).not.toContainText('—')
      await page.getByTestId('subject-country-edit').click()
      await page.getByTestId('subject-country-input').selectOption('fr')
      await page.getByTestId('subject-country-save').click()
      await expect(page.getByTestId('subject-country-value')).toHaveText('France')

      await expect(page.getByTestId('subject-level-value')).toHaveText('')
      await expect(page.getByTestId('subject-level-value')).not.toContainText('—')

      await page.getByTestId('subject-level-edit').click()
      await page.getByTestId('subject-level-input').selectOption('CM2')
      await page.getByTestId('subject-level-save').click()
      await expect(page.getByTestId('subject-level-value')).toHaveText('Dictée CM2 France')

      await page.getByTestId('subject-date-edit').click()
      await page.getByTestId('subject-date-input').fill('2026-12-01')
      await page.getByTestId('subject-date-save').click()
      await expect(page.getByTestId('subject-date-value')).toHaveText('2026-12-01')

      await page.getByTestId('subject-back').click()
      await page.waitForURL(/\/assessment\/[^/]+$/, { timeout: 15000 })
      await expect(page.getByTestId('assessment-details-heading')).toContainText('[Test] Math Final')
      await expect(page.getByTestId('assessment-meta')).toContainText('2026-12-01, Français (dictée)')
      await expect(page.getByTestId('assessment-subject-value')).toHaveText('Français (dictée)')
      await expect(page.getByTestId('assessment-date-value')).toHaveText('2026-12-01')
      await expect(page.getByTestId('assessment-level-value')).toHaveText('Dictée CM2 France')

      await page.reload()
      await expect(page.getByTestId('assessment-details-heading')).toContainText('[Test] Math Final')
      await expect(page.getByTestId('assessment-subject-value')).toHaveText('Français (dictée)')
      await expect(page.getByTestId('assessment-date-value')).toHaveText('2026-12-01')
      await expect(page.getByTestId('assessment-level-value')).toHaveText('Dictée CM2 France')

      await page.goto('assessment-list')
      await expect(page.getByTestId('assessment-item')).toContainText('[Test] Math Final')
      await expect(page.getByTestId('assessment-item')).toContainText('Français (dictée)')
      await expect(page.getByTestId('assessment-item')).toContainText('2026-12-01')
    })

    await test.step('Upload copies into the unassigned list', async () => {
      await page.getByTestId('assessment-item').click()
      await page.waitForURL(/\/assessment\/[^/]+$/, { timeout: 10000 })

      const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'corrai-assessment-'))
      const uploadPath = path.join(tmpDir, 'sample-scan.txt')
      fs.writeFileSync(uploadPath, 'Sample assessment scan content')
      const secondPath = path.join(tmpDir, 'second-scan.txt')
      fs.writeFileSync(secondPath, 'Second scan')

      await page.getByTestId('assessment-add-file').click()
      await expect(page.getByTestId('add-file-popup')).toBeVisible()
      await expect(page.getByTestId('add-file-type')).toHaveCount(0)
      await page.getByTestId('add-file-input').setInputFiles(uploadPath)

      await expect(page.getByTestId('unassigned-files').getByTestId('assessment-file-item')).toContainText(
        'sample-scan.txt',
        { timeout: 15000 }
      )
      await expect(page.getByTestId('file-type-zones')).toHaveCount(0)
      await expect(page.getByTestId('unassigned-files').getByTestId('file-reassign')).toBeVisible()
      await expect(page.getByTestId('assessment-test-correction')).toHaveText('Tester la correction', {
        timeout: 15000,
      })
      await expect(page.getByTestId('assessment-start-correction')).toBeVisible()

      await expect(page.getByTestId('add-file-popup')).toHaveCount(0)

      await page.getByTestId('assessment-add-file').click()
      await page.getByTestId('add-file-input').setInputFiles(secondPath)
      await expect(page.getByTestId('unassigned-files').getByTestId('assessment-file-item')).toHaveCount(2, {
        timeout: 15000
      })
      await expect(page.getByTestId('add-file-popup')).toHaveCount(0)

      fs.rmSync(tmpDir, { recursive: true, force: true })
    })

    await test.step('Reassign copies to a new student and an existing one', async () => {
      const firstRow = page.getByTestId('unassigned-files').getByTestId('assessment-file-item').filter({
        hasText: 'sample-scan.txt'
      })
      await firstRow.getByTestId('file-reassign').click()
      await expect(page.getByTestId('reassign-file-popup')).toBeVisible()
      await page.getByTestId('reassign-not-found').check()
      await expect(page.getByTestId('reassign-name-input')).toBeVisible()
      await page.getByTestId('reassign-name-input').fill('[Test] Alice')
      await page.getByTestId('reassign-confirm').click()
      await expect(page.getByTestId('reassign-file-popup')).toHaveCount(0, { timeout: 15000 })
      await expect(page.getByTestId('student-item')).toContainText('[Test] Alice')
      await expect(page.getByTestId('unassigned-files').getByTestId('assessment-file-item')).toHaveCount(1)

      await page.getByTestId('unassigned-files').getByTestId('file-reassign').click()
      await page.locator('.reassign-option', { hasText: '[Test] Alice' }).click()
      await page.getByTestId('reassign-confirm').click()
      await expect(page.getByTestId('reassign-file-popup')).toHaveCount(0, { timeout: 15000 })
      await expect(page.getByTestId('unassigned-files').getByTestId('assessment-file-item')).toHaveCount(0)
    })

    await test.step('Open the student page, then view, rename, and delete a file', async () => {
      await expect(page.getByTestId('student-view')).toHaveCount(0)
      await page.getByTestId('student-item').click()
      await page.waitForURL(/\/assessment\/[^/]+\/student\/[^/]+$/, { timeout: 10000 })
      await expect(page.getByTestId('student-name')).toHaveText('[Test] Alice')
      await expect(page.getByTestId('assessment-file-item')).toHaveCount(2)
      await expect(page.getByTestId('student-page').getByTestId('file-reassign')).toHaveCount(2)

      const sampleRow = page.getByTestId('assessment-file-item').filter({ hasText: 'sample-scan.txt' })
      const viewLink = sampleRow.getByTestId('file-view')
      await expect(viewLink).toHaveAttribute('href', /\/file\?/)
      await expect(viewLink).toHaveAttribute('target', '_blank')

      const popupPromise = page.waitForEvent('popup')
      await viewLink.click()
      const popup = await popupPromise
      await popup.waitForLoadState()
      expect(popup.url()).toMatch(/\/file\?/)
      await expect(popup.locator('body')).toContainText('Sample assessment scan content')
      await popup.close()

      await sampleRow.getByTestId('file-events').click()
      await expect(page.getByTestId('file-events-popup')).toBeVisible()
      await page.getByTestId('file-events-close').click()
      await expect(page.getByTestId('file-events-popup')).toHaveCount(0)

      await sampleRow.getByTestId('file-rename').click()
      await expect(page.getByTestId('rename-file-popup')).toBeVisible()
      await expect(page.getByTestId('rename-file-input')).toHaveValue('sample-scan.txt')
      await page.getByTestId('rename-file-input').fill('renamed-scan.txt')
      await page.getByTestId('rename-file-save').click()
      await expect(page.getByTestId('rename-file-popup')).toHaveCount(0, { timeout: 15000 })
      await expect(page.getByTestId('assessment-file-item').filter({ hasText: 'renamed-scan.txt' })).toBeVisible()

      const renamedRow = page.getByTestId('assessment-file-item').filter({ hasText: 'renamed-scan.txt' })
      await renamedRow.getByTestId('file-delete').click()
      await expect(page.getByTestId('delete-file-popup')).toBeVisible()
      await expect(page.getByTestId('delete-file-confirm')).toContainText('renamed-scan.txt')
      await page.getByTestId('delete-file-cancel').click()
      await expect(page.getByTestId('delete-file-popup')).toHaveCount(0)

      await renamedRow.getByTestId('file-delete').click()
      await page.getByTestId('delete-file-confirm-button').click()
      await expect(page.getByTestId('assessment-file-item')).toHaveCount(1, { timeout: 15000 })

      await page.getByTestId('student-back').click()
      await page.waitForURL(/\/assessment\/[^/]+$/, { timeout: 10000 })
    })

    await test.step('Rename and delete the student', async () => {
      await page.getByTestId('student-rename').click()
      await expect(page.getByTestId('rename-student-popup')).toBeVisible()
      await page.getByTestId('rename-student-input').fill('[Test] Alicia')
      await page.getByTestId('rename-student-save').click()
      await expect(page.getByTestId('rename-student-popup')).toHaveCount(0, { timeout: 15000 })
      await expect(page.getByTestId('student-item')).toContainText('[Test] Alicia')

      await page.getByTestId('student-delete').click()
      await expect(page.getByTestId('delete-student-popup')).toBeVisible()
      await page.getByTestId('delete-student-confirm-button').click()
      await expect(page.getByTestId('student-item')).toHaveCount(0, { timeout: 15000 })
      await expect(page.getByTestId('unassigned-files').getByTestId('assessment-file-item')).toContainText(
        'second-scan.txt'
      )
    })

    await test.step('Create and edit an instruction file', async () => {
      await page.getByTestId('assessment-edit-subject').click()
      await page.waitForURL(/\/assessment\/[^/]+\/sujet$/, { timeout: 10000 })
      await expect(page.getByTestId('subject-page')).toBeVisible()
      await expect(page.getByTestId('file-zone-subject').getByTestId('file-reassign')).toHaveCount(0)
      await expect(page.getByTestId('file-zone-instructions').locator('h2')).toHaveText('Consignes particulières')

      const instructionArea = page.getByTestId('file-zone-instructions').getByTestId('instruction-body')
      await expect(instructionArea).toBeVisible()
      await instructionArea.fill('Bring a calculator.')
      await expect(instructionArea).toHaveValue('Bring a calculator.')
      await instructionArea.blur()

      await instructionArea.fill('No phones during the assessment.')
      await expect(instructionArea).toHaveValue('No phones during the assessment.')
      await instructionArea.blur()
      await expect(page.getByTestId('file-zone-instructions').locator('.instruction-saving-indicator')).toHaveCount(0, { timeout: 10000 })
    })

    await test.step('Upload a solution file', async () => {
      await page.getByTestId('add-solution-file').click()
      await expect(page.getByTestId('add-file-popup')).toBeVisible()
      await page.getByTestId('add-file-input').setInputFiles({
        name: 'Corrige 1.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('The expected answer is 42.'),
      })
      await expect(page.getByTestId('add-file-status-list')).toContainText('Corrige 1.txt')
      await page.getByTestId('add-file-close').click()
      await expect(page.getByTestId('add-file-popup')).toHaveCount(0)
      await expect(
        page.getByTestId('file-zone-solution').getByTestId('assessment-file-item')
      ).toContainText('Corrige 1.txt')

      await page.getByTestId('subject-back').click()
      await page.waitForURL(/\/assessment\/[^/]+$/, { timeout: 10000 })
    })

    await test.step('Delete the assessment', async () => {
      page.once('dialog', async (dialog) => {
        expect(dialog.message()).toContain('supprimer cette évaluation')
        await dialog.accept()
      })

      await page.getByTestId('assessment-delete').click()
      await page.waitForURL('**/assessment-list', { timeout: 15000 })
      await expect(page.getByTestId('assessments-empty')).toBeVisible()
      await expect(page.getByTestId('assessment-item')).toHaveCount(0)
    })
  })

  test('does not show dash when date or level is missing', async ({ page }) => {
    await initProfile(page, '[Test] No Dash Tester')
    await page.getByTestId('assessment-create-button').click()
    await page.waitForURL('**/create_assessment', { timeout: 10000 })

    await page.getByTestId('assessment-no-subject').click()
    await page.getByTestId('assessment-name').fill('[Test] Undated Exam')
    await page.getByTestId('assessment-subject').selectOption('English')
    await page.getByTestId('assessment-submit').click()

    await page.waitForURL(/\/assessment\/[^/]+$/, { timeout: 15000 })
    await expect(page.getByTestId('assessment-meta')).toHaveText('Anglais')
    await expect(page.getByTestId('assessment-meta')).not.toContainText('—')
    await expect(page.getByTestId('assessment-date-value')).toHaveCount(0)
    await expect(page.getByTestId('assessment-level-value')).toHaveCount(0)

    await page.goto('assessment-list')
    const item = page.getByTestId('assessment-item').first()
    await expect(item.locator('.assessment-date')).toHaveText('')
    await expect(item.locator('.assessment-date')).not.toContainText('—')

    await item.click()
    await page.waitForURL(/\/assessment\/[^/]+$/, { timeout: 10000 })
    await page.getByTestId('assessment-edit-subject').click()
    await page.waitForURL(/\/assessment\/[^/]+\/sujet$/, { timeout: 10000 })

    await expect(page.getByTestId('subject-country-value')).toHaveText('')
    await expect(page.getByTestId('subject-country-value')).not.toContainText('—')
    await expect(page.getByTestId('subject-level-value')).toHaveText('')
    await expect(page.getByTestId('subject-level-value')).not.toContainText('—')
    await expect(page.getByTestId('subject-date-value')).toHaveText('')
    await expect(page.getByTestId('subject-date-value')).not.toContainText('—')
  })

  test('migrates a legacy public-key session to a server user id', async ({ page }) => {
    const publicKey =
      'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEO2M68bVhh9hununNXtZsqFnJeMjxIC+Vk5l7ncoMlXCrfFxkMp6OFEffvL/aiOyL1ND+rJZY+sfvEM1qaxNPug=='

    const assessmentAuthHeaders: string[] = []
    page.on('request', (request) => {
      if (request.url().includes('/api/assessments') && request.method() === 'GET') {
        assessmentAuthHeaders.push(request.headers()['authorization'] ?? '')
      }
    })

    await page.addInitScript((key) => {
      localStorage.setItem(
        'corrai-session',
        JSON.stringify({
          user_name: '[Test] Legacy User',
          locale: 'en',
          keyPair: { publicKey: key, privateKey: 'legacy-private-key' },
          user_id: key,
        })
      )
    }, publicKey)

    await page.goto('assessment-list')
    await expect(page.getByTestId('assessments-heading')).toHaveText('Assessments', { timeout: 20000 })
    await expect(page.getByTestId('assessments-empty')).toBeVisible({ timeout: 15000 })

    const session = await page.evaluate(() => {
      const raw = localStorage.getItem('corrai-session')
      return raw ? JSON.parse(raw) : null
    })
    expect(session?.user_id).toMatch(/^[0-9a-zA-Z]{7}$/)
    expect(session.user_id).not.toBe(publicKey)

    expect(assessmentAuthHeaders.length).toBeGreaterThan(0)
    for (const header of assessmentAuthHeaders) {
      expect(header).toMatch(/^Bearer [0-9a-zA-Z]{7}$/)
    }
  })
})
