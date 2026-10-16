<script setup lang="ts">
const emit = defineEmits<{
  authenticated: []
}>()
import axios from 'axios'
import { loginUser } from '../services/api'
import { computed, ref } from 'vue'
import { ArrowRight, CalendarDays, Eye, EyeOff, Globe2, LockKeyhole, ShieldCheck, UsersRound, ChartNoAxesColumnIncreasing, UserRound } from 'lucide-vue-next'

type Language = 'uz' | 'ru'
const language = ref<Language>('uz')
const username = ref('')
const password = ref('')
const visible = ref(false)
const error = ref('')
const copy = {
  uz: { hero: 'Sog‘liqni saqlash boshqaruvini soddalashtiramiz', intro: 'Blusser — xususiy klinikalar uchun qulay va ishonchli boshqaruv platformasi. Vaqtingizni tejang, jarayonlarni avtomatlashtiring, bemorlaringizga ko‘proq e’tibor bering.', features: ['Qabul va navbatlar', 'Xodimlar va lavozimlar', 'Hisobotlar va statistika', 'Xavfsiz va ishonchli'], hello: 'Xush kelibsiz!', detail: 'Blusser klinika tizimiga kirish', user: 'Login', userPlaceholder: 'Loginingizni kiriting', pass: 'Parol', passPlaceholder: 'Parolingizni kiriting', forgot: 'Parolni unutdingizmi?', submit: 'Kirish', support: 'Tizimga kirish uchun yordam', required: 'Login va parolni kiriting.', pending: 'Login API ulanishi hali sozlanmagan. Soxta kirish bajarilmaydi.' },
  ru: { hero: 'Упрощаем управление здравоохранением', intro: 'Blusser — удобная платформа управления частными клиниками. Экономьте время и уделяйте больше внимания пациентам.', features: ['Приём и очереди', 'Сотрудники и должности', 'Отчёты и аналитика', 'Безопасность'], hello: 'Добро пожаловать!', detail: 'Вход в систему Blusser', user: 'Логин', userPlaceholder: 'Введите логин', pass: 'Пароль', passPlaceholder: 'Введите пароль', forgot: 'Забыли пароль?', submit: 'Войти', support: 'Помощь со входом', required: 'Введите логин и пароль.', pending: 'Авторизация пока не подключена. Демонстрационный вход отключён.' }
}
const t = computed(() => copy[language.value])
const icons = [CalendarDays, UsersRound, ChartNoAxesColumnIncreasing, ShieldCheck]

const isLoading = ref(false)


async function submitLogin() {
  if (!username.value.trim() || !password.value) {
    error.value = 'Login va parolni kiriting.'
    return
  }

  isLoading.value = true
  error.value = ''

  try {
    const user = await loginUser(username.value.trim(), password.value)
    password.value = ''
    if (user.must_change_password) {
      error.value = 'Parolni almashtirish talab qilinadi.'
      return
    }

    error.value = ''
    emit('authenticated')
  } catch (err) {
    if (axios.isAxiosError(err)) {
      if (err.response?.status === 419) {
        error.value = 'Sessiya muddati tugagan. Sahifani yangilang.'
      } else if ([401, 422].includes(err.response?.status ?? 0)) {
        error.value = 'Login yoki parol noto‘g‘ri.'
      } else {
        error.value = 'Server bilan bog‘lanishda xatolik.'
      }
    } else {
      error.value = 'Kutilmagan xatolik yuz berdi.'
    }
  } finally {
    isLoading.value = false
  }
}


</script>

<template>
  <main class="bl-login">
    <section class="bl-intro">
      <div class="bl-intro-inner">
        <div class="bl-brand" aria-label="Blusser Medical Software"><span class="bl-symbol"><i/><b/></span><span class="bl-word"><strong>Blusser</strong><small>MEDICAL SOFTWARE</small></span></div>
        <div class="bl-intro-content">
          <h1>{{ t.hero }}</h1>
          <p>{{ t.intro }}</p>
          <div class="bl-features"><div v-for="(item, index) in t.features" :key="index" class="bl-feature"><span><component :is="icons[index]" :size="23" :stroke-width="1.9" /></span>{{ item }}</div></div>
        </div>
        <div class="bl-intro-foot">BLUSSER · MEDICAL SOFTWARE</div>
      </div>
    </section>
    <section class="bl-form-area">
      <div class="bl-language"><Globe2 :size="18"/><select v-model="language" aria-label="Tilni tanlash"><option value="uz">O‘zbekcha</option><option value="ru">Русский</option></select></div>
      <div class="bl-form-wrap">
        <div class="bl-form-brand"><span class="bl-symbol"><i/><b/></span><strong>Blusser</strong></div>
        <h2>{{ t.hello }}</h2><p class="bl-subtitle">{{ t.detail }}</p>
        <form @submit.prevent="submitLogin" novalidate>
          <label class="bl-label" for="bl-user">{{ t.user }}</label>
          <div class="bl-input"><UserRound :size="20"/><input id="bl-user" v-model="username" autocomplete="username" :placeholder="t.userPlaceholder" /></div>
          <label class="bl-label" for="bl-pass">{{ t.pass }}</label>
          <div class="bl-input"><LockKeyhole :size="20"/><input id="bl-pass" v-model="password" :type="visible ? 'text' : 'password'" autocomplete="current-password" :placeholder="t.passPlaceholder"/><button class="bl-eye" type="button" :aria-label="visible ? 'Hide password' : 'Show password'" @click="visible = !visible"><EyeOff v-if="visible" :size="20"/><Eye v-else :size="20"/></button></div>
          <div class="bl-form-meta"><span></span><span class="bl-forgot">{{ t.forgot }}</span></div>
          <p v-if="error" class="bl-error" role="alert">{{ error }}</p>
          <button class="bl-submit" type="submit" :disabled="isLoading">
  {{ isLoading ? 'Tekshirilmoqda...' : t.submit }}
  <ArrowRight v-if="!isLoading" :size="19" />
</button>
        </form>
        <div class="bl-help"><ShieldCheck :size="19"/> {{ t.support }}</div>
      </div>
      <div class="bl-copyright">© 2026 Blusser Medical Software</div>
    </section>
  </main>
</template>

<style>
.bl-login{width:100%;min-height:100vh;display:grid;grid-template-columns:51% 49%;color:#102345;font-family:Inter,Arial,sans-serif}.bl-intro{background:linear-gradient(140deg,#edf6ff 0%,#e9f4ff 55%,#ecfbfb 100%)}.bl-intro-inner{padding:clamp(32px,4vw,62px) clamp(30px,6.2vw,104px) 28px;min-height:100vh;display:flex;flex-direction:column}.bl-brand{display:flex;align-items:center;gap:16px}.bl-word{display:flex;flex-direction:column}.bl-word strong{font-size:42px;letter-spacing:-2px;font-weight:800;line-height:1.08}.bl-word small{font-size:10px;letter-spacing:3.3px;color:#627e9b;font-weight:700}.bl-symbol{width:64px;height:74px;display:block;position:relative;flex-shrink:0}.bl-symbol i,.bl-symbol b{position:absolute;left:0;width:100%;height:47%;display:block;border-radius:0 35px 35px 0}.bl-symbol i{top:0;background:linear-gradient(110deg,#0962ff,#349aff)}.bl-symbol b{bottom:0;background:linear-gradient(115deg,#00aab7,#27d8bc);border-radius:0 35px 4px 30px}.bl-intro-content{margin:auto 0;padding:56px 0 40px}.bl-intro h1{font-size:clamp(34px,3.25vw,54px);line-height:1.2;letter-spacing:-1.9px;margin:0 0 30px;font-weight:800;max-width:570px}.bl-intro-content>p{font-size:16px;line-height:1.9;color:#5b7699;max-width:560px;margin:0}.bl-features{display:grid;grid-template-columns:1fr 1fr;gap:30px 20px;margin-top:48px}.bl-feature{display:flex;align-items:center;gap:14px;color:#244365;font-size:14px;line-height:1.5}.bl-feature>span{display:grid;place-items:center;width:52px;height:52px;border:1px solid #cde0ff;background:#e1efff;color:#1768ff;border-radius:14px;flex-shrink:0}.bl-intro-foot{color:#8aa0b7;letter-spacing:2.2px;font-size:10px}.bl-form-area{background:#fff;min-height:100vh;padding:90px 42px 64px;display:flex;justify-content:center;align-items:center;position:relative}.bl-language{position:absolute;top:34px;right:clamp(28px,5vw,90px);display:flex;align-items:center;gap:8px;color:#365676}.bl-language select{font:inherit;border:none;background:transparent;color:inherit;cursor:pointer;outline-offset:4px}.bl-form-wrap{width:100%;max-width:452px}.bl-form-brand{display:flex;flex-direction:column;align-items:center;gap:10px;margin-bottom:36px}.bl-form-brand .bl-symbol{width:50px;height:60px}.bl-form-brand strong{font-size:40px;letter-spacing:-1.6px;line-height:1;font-weight:800}.bl-form-area h2{text-align:center;font-size:30px;margin:0 0 12px;letter-spacing:-.65px}.bl-subtitle{text-align:center;color:#6c85a6;font-size:15px;margin:0 0 42px}.bl-label{display:block;font-size:14px;font-weight:600;margin:0 0 10px}.bl-input{height:56px;border:1px solid #d5e1f1;border-radius:11px;display:flex;align-items:center;padding:0 16px;gap:14px;color:#7895b9;margin-bottom:24px}.bl-input:focus-within{border-color:#2563eb;box-shadow:0 0 0 3px #2563eb13}.bl-input input{border:0;background:none;outline:0;min-width:0;flex:1;color:#122849;font:inherit;font-size:15px}.bl-input input::placeholder{color:#9bb0ca}.bl-eye{border:0;background:transparent;display:flex;cursor:pointer;color:#7895b9}.bl-form-meta{display:flex;justify-content:flex-end;font-size:13px;margin:0 0 30px}.bl-forgot{color:#2563eb}.bl-submit{width:100%;height:56px;background:#2563eb;border:0;border-radius:10px;color:white;font-size:16px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:12px;cursor:pointer}.bl-submit:hover{background:#1554d4}.bl-help{margin-top:30px;border:1px solid #d9e4f2;border-radius:10px;min-height:54px;display:flex;align-items:center;justify-content:center;gap:10px;color:#617d9f;font-size:13px}.bl-error{font-size:13px;color:#a34812;padding:10px;background:#fff5e6;border-radius:8px}.bl-copyright{position:absolute;bottom:23px;font-size:12px;color:#97a8bb}@media(max-width:900px){.bl-login{grid-template-columns:1fr}.bl-intro{display:none}.bl-form-area{padding:100px 24px 65px}.bl-language{right:24px}.bl-form-wrap{max-width:470px}}@media(max-height:780px) and (min-width:901px){.bl-form-area{align-items:flex-start}.bl-form-brand{margin-bottom:18px}.bl-subtitle{margin-bottom:24px}.bl-input{margin-bottom:16px}}
</style>
