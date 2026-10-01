<template>
  <div class="app">
    <div class="card">
      <header class="account-header">
        <h1>
          {{ $t('settings.title') }}
        </h1>
        <p class="muted">{{ $t('settings.subtitle') }}</p>
      </header>
      <div>
        <div class="settings-sections">
          <section>
            <h2>{{ $t('settings.credentials') }}</h2>
            <div class="field-list">
              <div class="field-row">
                <span class="field-label">{{ $t('settings.name') }}</span>
                <div class="field-control">
                  <template v-if="editing === 'name'">
                    <input
                      id="user_name"
                      v-model="draft"
                      type="text"
                      class="input identity-input"
                      data-testid="settings-name-input"
                      :disabled="isSavingIdentity"
                      @keydown.enter.prevent="saveIdentity"
                    />
                  </template>
                  <span v-else class="identity-value" data-testid="settings-name">{{
                    sessionStore.user_name || '—'
                  }}</span>
                </div>
                <div class="field-actions">
                  <template v-if="editing === 'name'">
                    <button
                      type="button"
                      class="button secondary compact"
                      :disabled="isSavingIdentity"
                      @click="cancelEdit"
                    >
                      {{ $t('common.cancel') }}
                    </button>
                    <button
                      type="button"
                      class="button primary compact"
                      data-testid="settings-name-save"
                      :disabled="isSavingIdentity || !draft.trim()"
                      @click="saveIdentity"
                    >
                      {{ $t('assessment.save') }}
                    </button>
                  </template>
                  <button
                    v-else
                    type="button"
                    class="button secondary compact"
                    data-testid="settings-name-edit"
                    @click="startEdit('name')"
                  >
                    {{ $t('assessment.edit') }}
                  </button>
                </div>
              </div>

              <div class="field-row">
                <span class="field-label">{{ $t('settings.email') }}</span>
                <div class="field-control">
                  <template v-if="editing === 'email'">
                    <input
                      id="user_email"
                      v-model="draft"
                      type="email"
                      class="input identity-input"
                      data-testid="settings-email-input"
                      autocomplete="email"
                      :disabled="isSavingIdentity"
                      @keydown.enter.prevent="saveIdentity"
                    />
                  </template>
                  <span v-else class="identity-value" data-testid="settings-email">{{
                    sessionStore.user_email || '—'
                  }}</span>
                </div>
                <div class="field-actions">
                  <template v-if="editing === 'email'">
                    <button
                      type="button"
                      class="button secondary compact"
                      :disabled="isSavingIdentity"
                      @click="cancelEdit"
                    >
                      {{ $t('common.cancel') }}
                    </button>
                    <button
                      type="button"
                      class="button primary compact"
                      data-testid="settings-email-save"
                      :disabled="isSavingIdentity || !draft.trim()"
                      @click="saveIdentity"
                    >
                      {{ $t('assessment.save') }}
                    </button>
                  </template>
                  <button
                    v-else
                    type="button"
                    class="button secondary compact"
                    data-testid="settings-email-edit"
                    @click="startEdit('email')"
                  >
                    {{ $t('assessment.edit') }}
                  </button>
                </div>
              </div>

              <div class="field-row">
                <span class="field-label">{{ $t('settings.password') }}</span>
                <div class="field-control">
                  <template v-if="editing === 'password'">
                    <div class="password-fields">
                      <input
                        id="user_password"
                        v-model="draft"
                        type="password"
                        class="input identity-input"
                        data-testid="settings-password-input"
                        autocomplete="new-password"
                        :disabled="isSavingIdentity"
                      />
                      <input
                        id="user_password_confirm"
                        v-model="draftConfirm"
                        type="password"
                        class="input identity-input"
                        data-testid="settings-password-confirm"
                        autocomplete="new-password"
                        :placeholder="$t('settings.password_confirm')"
                        :disabled="isSavingIdentity"
                        @keydown.enter.prevent="saveIdentity"
                      />
                    </div>
                  </template>
                  <span v-else class="identity-value">••••••••</span>
                </div>
                <div class="field-actions">
                  <template v-if="editing === 'password'">
                    <button
                      type="button"
                      class="button secondary compact"
                      :disabled="isSavingIdentity"
                      @click="cancelEdit"
                    >
                      {{ $t('common.cancel') }}
                    </button>
                    <button
                      type="button"
                      class="button primary compact"
                      data-testid="settings-password-save"
                      :disabled="isSavingIdentity || !draft"
                      @click="saveIdentity"
                    >
                      {{ $t('assessment.save') }}
                    </button>
                  </template>
                  <button
                    v-else
                    type="button"
                    class="button secondary compact"
                    data-testid="settings-password-edit"
                    @click="startEdit('password')"
                  >
                    {{ $t('assessment.edit') }}
                  </button>
                </div>
              </div>
              <p v-if="identityError" class="field-error" data-testid="settings-identity-error">
                {{ identityError }}
              </p>
            </div>
            <div class="logout-row">
              <button type="button" class="button" data-testid="settings-logout" @click="handleLogout">
                {{ $t('nav.logout') }}
              </button>
            </div>
          </section>

          <section>
            <h2>{{ $t('settings.billing') }}</h2>
            <p class="muted billing-empty">{{ $t('settings.billing_empty') }}</p>
          </section>

          <section>
            <h2>{{ $t('settings.preferences') }}</h2>
            <div class="field-list">
              <div class="field-row">
                <label class="field-label" for="user_country">{{ $t('settings.country') }}</label>
                <div class="field-control">
                <select
                  id="user_country"
                  data-testid="settings-country"
                  :value="sessionStore.country"
                  class="select"
                  :disabled="isSavingCountry"
                  @change="onCountryChange"
                >
                  <option v-for="country in countries" :key="country.code" :value="country.code">
                    {{ country.name }}
                  </option>
                </select>
                </div>
              </div>
              <div class="field-row">
                <label class="field-label" for="user_language">{{ $t('language') }}</label>
                <div class="field-control">
                <select
                  id="user_language"
                  data-testid="settings-language"
                  :value="sessionStore.locale"
                  class="select"
                  @change="onLocaleChange"
                >
                  <option v-for="code in locales" :key="code" :value="code">
                    {{ languageName(code) }}
                  </option>
                </select>
                </div>
              </div>
              <div class="field-row">
                <label class="field-label" for="debug_mode">{{ $t('settings.debug_mode') }}</label>
                <div class="field-control">
                  <input
                    id="debug_mode"
                    type="checkbox"
                    class="debug-checkbox"
                    data-testid="settings-debug-mode"
                    :checked="sessionStore.debugMode"
                    @change="onDebugModeChange"
                  />
                </div>
              </div>
            </div>
          </section>

          <section v-if="notificationService.isSupported()">
            <h2>{{ $t('settings.notifications') }}</h2>
            <div class="notice-block">
              <div
                v-if="notificationService.getPermission() === 'denied'"
                class="status-banner status-banner--warning"
              >
                <p class="status-banner__text">
                  {{ $t('settings.notifications_permission_denied_warning') }}
                </p>
                <button
                  @click.prevent.stop="requestNotificationPermission"
                  :disabled="isRequestingNotificationPermission"
                  class="test-button test-button--warning"
                >
                  {{ isRequestingNotificationPermission ? '...' : $t('settings.enable_notifications') }}
                </button>
              </div>
              <div v-else-if="notificationService.getPermission() === 'granted'" class="notice-granted">
                <div class="status-banner status-banner--success">
                  <p class="status-banner__text">
                    {{ $t('settings.notifications_enabled_status') }}
                  </p>
                </div>
                <div class="notice-actions">
                  <button
                    @click.prevent.stop="createWebpushSubscription"
                    :disabled="isCreatingWebpushSubscription"
                    class="test-button"
                  >
                    {{ isCreatingWebpushSubscription ? '...' : $t('settings.create_webpush_subscription') }}
                  </button>
                  <button
                    @click.prevent.stop="resetNotifications"
                    :disabled="isResettingNotifications"
                    class="test-button test-button--danger"
                  >
                    {{ isResettingNotifications ? '...' : $t('settings.reset_notifications') }}
                  </button>
                </div>
              </div>
              <div
                v-else-if="notificationService.getPermission() === 'default'"
                class="status-banner status-banner--info"
              >
                <p class="status-banner__text">
                  {{ $t('settings.notifications_not_requested') }}
                </p>
                <button
                  @click.prevent.stop="requestNotificationPermission"
                  :disabled="isRequestingNotificationPermission"
                  class="test-button test-button--info"
                >
                  {{ isRequestingNotificationPermission ? '...' : $t('settings.enable_notifications') }}
                </button>
              </div>
            </div>
          </section>

          <div class="account-danger">
            <button
              type="button"
              class="button danger"
              data-testid="settings-delete-account"
              @click="showDeleteConfirm = true"
            >
              {{ $t('settings.delete_account') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <div
      v-if="showDeleteConfirm"
      class="popup-overlay"
      data-testid="delete-account-popup"
      @click.self="closeDeleteConfirm"
    >
      <div class="popup-content delete-account-popup">
        <div class="popup-header">
          <h2>{{ $t('settings.delete_account_title') }}</h2>
          <button
            type="button"
            class="close-button"
            data-testid="delete-account-close"
            :aria-label="$t('common.cancel')"
            :disabled="isDeletingAccount"
            @click="closeDeleteConfirm"
          >
            &times;
          </button>
        </div>
        <div class="popup-body">
          <p data-testid="delete-account-confirm">{{ $t('settings.delete_account_confirm') }}</p>
          <p v-if="deleteError" class="field-error" data-testid="delete-account-error">{{ deleteError }}</p>
        </div>
        <div class="popup-footer">
          <button
            type="button"
            class="button secondary"
            data-testid="delete-account-cancel"
            :disabled="isDeletingAccount"
            @click="closeDeleteConfirm"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            type="button"
            class="button danger"
            data-testid="delete-account-confirm-button"
            :disabled="isDeletingAccount"
            @click="confirmDeleteAccount"
          >
            {{ $t('settings.delete_account') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import { useNotificationService } from '@/services/notifications'
import { localizedCountries } from '@/data/countries'
import { AVAILABLE_LOCALES, isValidLocale, loadLanguage } from '@/i18n'

const sessionStore = useSessionStore()
const router = useRouter()
const { t, locale } = useI18n()
const notificationService = useNotificationService()
const isSavingCountry = ref(false)

const countries = computed(() => localizedCountries(locale.value))
const locales = AVAILABLE_LOCALES

type IdentityField = 'name' | 'email' | 'password'
const editing = ref<IdentityField | null>(null)
const draft = ref('')
const draftConfirm = ref('')
const identityError = ref('')
const isSavingIdentity = ref(false)
const showDeleteConfirm = ref(false)
const isDeletingAccount = ref(false)
const deleteError = ref('')

onMounted(async () => {
  try {
    await sessionStore.loadUser()
  } catch (error) {
    console.error('Error loading user:', error)
  }
})

const languageName = (code: string) => {
  try {
    const names = new Intl.DisplayNames([String(locale.value || 'fr')], { type: 'language' })
    const name = names.of(code) || code
    return name.charAt(0).toUpperCase() + name.slice(1)
  } catch {
    return code
  }
}

const handleLogout = () => {
  sessionStore.clearSession()
  router.push('/login')
}

const startEdit = (field: IdentityField) => {
  editing.value = field
  identityError.value = ''
  draftConfirm.value = ''
  if (field === 'name') {
    draft.value = sessionStore.user_name
  } else if (field === 'email') {
    draft.value = sessionStore.user_email
  } else {
    draft.value = ''
  }
}

const cancelEdit = () => {
  editing.value = null
  draft.value = ''
  draftConfirm.value = ''
  identityError.value = ''
}

const saveIdentity = async () => {
  if (!editing.value || isSavingIdentity.value) {
    return
  }

  const field = editing.value
  const value = draft.value.trim()
  if (field !== 'password' && value === '') {
    return
  }
  if (field === 'password') {
    if (draft.value === '') {
      return
    }
    if (draft.value !== draftConfirm.value) {
      identityError.value = t('settings.password_mismatch')
      return
    }
  }
  if (field === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
    identityError.value = t('settings.identity_error')
    return
  }

  isSavingIdentity.value = true
  identityError.value = ''
  try {
    if (field === 'name') {
      await sessionStore.saveUser({ name: value })
    } else if (field === 'email') {
      await sessionStore.saveUser({ email: value })
    } else {
      await sessionStore.saveUser({ password: draft.value })
    }
    cancelEdit()
  } catch (error) {
    console.error('Error saving identity:', error)
    identityError.value = t('settings.identity_error')
  } finally {
    isSavingIdentity.value = false
  }
}

const closeDeleteConfirm = () => {
  if (isDeletingAccount.value) {
    return
  }
  showDeleteConfirm.value = false
  deleteError.value = ''
}

const confirmDeleteAccount = async () => {
  if (isDeletingAccount.value) {
    return
  }
  isDeletingAccount.value = true
  deleteError.value = ''
  try {
    await sessionStore.deleteAccount()
    showDeleteConfirm.value = false
    router.push('/login')
  } catch (error) {
    console.error('Error deleting account:', error)
    deleteError.value = t('settings.delete_account_error')
  } finally {
    isDeletingAccount.value = false
  }
}

const isRequestingNotificationPermission = ref(false)
const isResettingNotifications = ref(false)
const isCreatingWebpushSubscription = ref(false)

const requestNotificationPermission = async () => {
  isRequestingNotificationPermission.value = true
  try {
    const permission = await notificationService.requestPermission()

    if (permission === 'granted') {
      const success = await notificationService.subscribeAndSend()
      if (success) {
        alert(t('settings.notifications_enabled'))
      } else {
        alert(t('settings.notifications_error'))
      }
    } else if (permission === 'denied') {
      alert(t('settings.notifications_permission_denied'))
    }
  } catch (error) {
    console.error('Error requesting notification permission:', error)
    alert(t('settings.notifications_error'))
  } finally {
    isRequestingNotificationPermission.value = false
  }
}

const createWebpushSubscription = async () => {
  if (isCreatingWebpushSubscription.value) {
    return
  }

  isCreatingWebpushSubscription.value = true

  try {
    const success = await notificationService.subscribeAndSend()

    if (success) {
      alert(t('settings.notifications_enabled'))
    } else {
      alert(t('settings.notifications_error'))
    }
  } catch (error) {
    console.error('Error creating web push subscription:', error)
    alert(t('settings.notifications_error'))
  } finally {
    isCreatingWebpushSubscription.value = false
  }
}

const resetNotifications = async () => {
  if (!confirm(t('settings.reset_notifications_confirm'))) {
    return
  }

  isResettingNotifications.value = true
  try {
    const success = await notificationService.unsubscribeAndNotify()
    if (success) {
      alert(t('settings.notifications_reset_success'))
      window.location.reload()
    } else {
      alert(t('settings.notifications_reset_error'))
    }
  } catch (error) {
    console.error('Error resetting notifications:', error)
    alert(t('settings.notifications_reset_error'))
  } finally {
    isResettingNotifications.value = false
  }
}

const onCountryChange = async (event: Event) => {
  const target = event.target as HTMLSelectElement
  const next = target.value
  if (next === sessionStore.country || isSavingCountry.value) {
    return
  }
  isSavingCountry.value = true
  try {
    await sessionStore.saveCountry(next)
  } catch (error) {
    console.error('Error saving country:', error)
    target.value = sessionStore.country
    alert(t('settings.country_error'))
  } finally {
    isSavingCountry.value = false
  }
}

const onLocaleChange = async (event: Event) => {
  const target = event.target as HTMLSelectElement
  const next = target.value
  if (!isValidLocale(next) || next === sessionStore.locale) {
    return
  }
  sessionStore.setLocale(next)
  await loadLanguage(next)
}

const onDebugModeChange = (event: Event) => {
  const target = event.target as HTMLInputElement
  sessionStore.setDebugMode(target.checked)
}
</script>

<style scoped>
.account-header {
  margin-bottom: 8px;
}

.account-header h1 {
  margin-bottom: 0.25rem;
}

.settings-sections > section {
  padding-top: 22px;
  margin-top: 8px;
  border-top: 1px solid var(--border);
}

.settings-sections > section h2 {
  margin: 0 0 10px;
}

.billing-empty {
  margin: 0 0 8px;
}

.field-list {
  display: flex;
  flex-direction: column;
}

.field-row {
  display: grid;
  grid-template-columns: 9.5rem minmax(0, 1fr) auto;
  column-gap: 20px;
  row-gap: 8px;
  align-items: center;
  min-height: 52px;
  padding: 6px 0;
  border-bottom: 1px solid color-mix(in srgb, var(--border) 85%, transparent);
}

.field-row:last-child {
  border-bottom: none;
}

.field-label {
  font-weight: 600;
  color: var(--ink);
}

.field-control {
  min-width: 0;
}

.identity-value {
  color: var(--text);
}

.field-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
}

.identity-input,
.select {
  width: min(100%, 360px);
  min-width: 0;
  box-sizing: border-box;
}

.password-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 360px));
  gap: 8px;
}

.debug-checkbox {
  width: 16px;
  height: 16px;
  margin: 0;
  accent-color: var(--blue);
}

.logout-row {
  display: grid;
  grid-template-columns: 9.5rem minmax(0, 1fr);
  column-gap: 20px;
  margin-top: 14px;
}

.logout-row .button {
  grid-column: 2;
  justify-self: start;
}

.field-list .field-error {
  margin: 4px 0 0;
  padding-left: calc(9.5rem + 20px);
}

.notice-block,
.notice-granted {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.notice-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.account-danger {
  display: flex;
  justify-content: flex-end;
  margin-top: 28px;
  padding-top: 20px;
  border-top: 1px solid var(--border);
}

@media (max-width: 720px) {
  .field-row {
    grid-template-columns: 1fr;
    min-height: 0;
    padding: 12px 0;
  }

  .field-actions {
    justify-content: flex-start;
  }

  .password-fields {
    grid-template-columns: 1fr;
  }

  .identity-input,
  .select {
    width: 100%;
  }

  .logout-row,
  .field-list .field-error {
    display: block;
    padding-left: 0;
  }

  .logout-row .button {
    grid-column: auto;
  }
}

.field-error {
  color: var(--danger);
  font-weight: 600;
}

.button.compact {
  padding: 8px 14px;
}

.delete-account-popup {
  width: min(440px, calc(100vw - 32px));
}

.select {
  padding: 10px 12px;
  border-radius: var(--radius-md);
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text);
  cursor: pointer;
}

.select:hover {
  border-color: var(--muted);
}

.select:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--accent) 25%, transparent);
}

.test-button {
  padding: 12px 18px;
  border: none;
  border-radius: var(--radius-md);
  background-color: var(--accent);
  color: white;
  cursor: pointer;
  font-size: var(--type-label);
  transition: all 0.2s;
  min-width: 120px;
}

.test-button:hover:not(:disabled) {
  background-color: var(--accent-600);
  transform: translateY(-1px);
}

.test-button:active:not(:disabled) {
  transform: translateY(0);
}

.test-button:disabled {
  background-color: var(--disabled);
  cursor: not-allowed;
  opacity: 0.6;
}

.test-button--warning {
  background-color: var(--warning);
  color: white;
  box-shadow: none;
}

.test-button--warning:hover:not(:disabled) {
  background-color: color-mix(in srgb, var(--warning) 85%, black);
}

.test-button--danger {
  background-color: var(--danger);
}

.test-button--danger:hover:not(:disabled) {
  background-color: var(--danger-600);
}

.test-button--info {
  background-color: var(--info);
  color: white;
  box-shadow: none;
}

.test-button--info:hover:not(:disabled) {
  background-color: color-mix(in srgb, var(--info) 85%, black);
}

.status-banner {
  padding: 14px;
  border-radius: var(--radius-md);
}

.status-banner__text {
  margin: 0;
  margin-bottom: 8px;
  font-weight: 500;
  pointer-events: none;
}

.status-banner--warning {
  background-color: color-mix(in srgb, var(--warning) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--warning) 40%, transparent);
}

.status-banner--warning .status-banner__text {
  color: var(--warning);
}

.status-banner--success {
  background-color: color-mix(in srgb, var(--success) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--success) 40%, transparent);
}

.status-banner--success .status-banner__text {
  color: var(--success);
  margin-bottom: 0;
}

.status-banner--info {
  background-color: color-mix(in srgb, var(--info) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--info) 40%, transparent);
}

.status-banner--info .status-banner__text {
  color: var(--info);
}
</style>
