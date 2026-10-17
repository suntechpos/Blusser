<script setup lang="ts">
import { computed, ref, watch } from "vue";
import axios from "axios";
import {
  Search,
  Plus,
  Users,
  Download,
  Eye,
  Pencil,
  MoreHorizontal,
  ChevronLeft,
  ChevronRight,
  X,
} from "lucide-vue-next";
import ApiState from "../components/operations/ApiState.vue";
import { useClinicList } from "../composables/useClinicList";
import { clinicRequest, errorText, downloadBlob } from "../services/api";
import { can, session, selectBranch } from "../services/session";
import type { Patient as RecordPatient } from "../services/operations";
type Patient = RecordPatient & {
  dob: string;
  last: string;
  visits: string;
  status: string;
  branch: string;
};
const search = ref(""),
  gender = ref(""),
  status = ref(""),
  visitRange = ref(""),
  tab = ref("Barcha bemorlar"),
  size = ref(10);
const branch = computed({ get: () => session.branch, set: selectBranch });
const list = useClinicList<RecordPatient>(
  "patients",
  () => ({
    ...(search.value.trim() ? { q: search.value.trim() } : {}),
    per_page: size.value,
    status: tab.value === "Faol" ? "active" : "all",
  }),
  () => can("patients.view"),
);
const { page, loading, error, load, total } = list;
function present(p: RecordPatient): Patient {
  return {
    ...p,
    dob: p.date_of_birth,
    gender:
      p.gender === "female" ? "Ayol" : p.gender === "male" ? "Erkak" : "—",
    last: "",
    visits: "—",
    status: p.archived_at ? "Arxiv" : "Faol",
    branch: p.branch_ids
      .map((id) => session.branches.find((b) => b.id === id)?.name ?? id)
      .join(", "),
  };
}
const patients = computed(() => list.rows.value.map(present));
const visible = patients,
  currentPage = page,
  totalPages = computed(() => Math.max(1, Math.ceil(total.value / size.value)));
const pageNumbers = computed(() =>
  Array.from(
    { length: Math.min(7, totalPages.value) },
    (_, i) => Math.max(1, Math.min(page.value - 3, totalPages.value - 6)) + i,
  ),
);
const selected = ref<Patient | null>(null),
  mode = ref<"view" | "edit" | "create">("view"),
  notice = ref(""),
  saving = ref(false),
  etag = ref(""),
  detailLoading = ref(false);
const form = ref({ name: "", phone: "", dob: "", gender: "Ayol", branch: "" });
const tabs = ["Barcha bemorlar", "Yangi", "Faol", "Nazoratda"];
const count = (t: string) =>
  t === tab.value && !loading.value && !error.value ? total.value : "—";
const age = (s: string) => {
  const d = new Date(s),
    n = new Date();
  return (
    n.getFullYear() -
    d.getFullYear() -
    (n.getMonth() < d.getMonth() ||
    (n.getMonth() === d.getMonth() && n.getDate() < d.getDate())
      ? 1
      : 0)
  );
};
let detailSequence = 0;
function create() {
  detailSequence++;
  detailLoading.value = false;
  selected.value = null;
  mode.value = "create";
  form.value = {
    name: "",
    phone: "",
    dob: "",
    gender: "Ayol",
    branch:
      session.branch ||
      (session.branches.length === 1 ? session.branches[0]!.id : ""),
  };
  notice.value = "";
}
async function open(p: Patient, next: "view" | "edit") {
  const sequence = ++detailSequence;
  detailLoading.value = true;
  notice.value = "";
  selected.value = null;
  mode.value = "view";
  try {
    const r = await clinicRequest<RecordPatient>("patients/" + p.id);
    if (sequence !== detailSequence) return;
    etag.value = String(r.headers.etag ?? "");
    selected.value = present(r.data);
    mode.value = next;
    form.value = {
      name: r.data.name,
      phone: r.data.phone ?? "",
      dob: r.data.date_of_birth,
      gender:
        r.data.gender === "male"
          ? "Erkak"
          : r.data.gender === "female"
            ? "Ayol"
            : "—",
      branch: r.data.branch_ids[0] ?? "",
    };
  } catch (e) {
    if (sequence === detailSequence && !axios.isCancel(e))
      notice.value = errorText(e);
  } finally {
    if (sequence === detailSequence) detailLoading.value = false;
  }
}
const edit = (p: Patient) => open(p, "edit");
function close() {
  detailSequence++;
  detailLoading.value = false;
  selected.value = null;
  mode.value = "view";
  etag.value = "";
  form.value = { name: "", phone: "", dob: "", gender: "Ayol", branch: "" };
}
async function save() {
  if (saving.value) return;
  saving.value = true;
  notice.value = "";
  try {
    const payload = {
      name: form.value.name,
      phone: form.value.phone || null,
      date_of_birth: form.value.dob,
      gender:
        form.value.gender === "Ayol"
          ? "female"
          : form.value.gender === "Erkak"
            ? "male"
            : "unspecified",
    };
    if (mode.value === "edit" && selected.value) {
      if (!etag.value) throw Error("Versiya olinmadi. Bemorni qayta oching.");
      await clinicRequest("patients/" + selected.value.id, {
        method: "PATCH",
        body: payload,
        etag: etag.value,
      });
    } else
      await clinicRequest("patients", {
        method: "POST",
        body: { ...payload, branch_id: form.value.branch },
      });
    notice.value = "Ma’lumot saqlandi.";
    close();
    await load();
  } catch (e) {
    if (!axios.isCancel(e)) notice.value = errorText(e);
  } finally {
    saving.value = false;
  }
}
async function exportCsv() {
  try {
    const r = await clinicRequest<Blob>("patients/export", {
      download: true,
      params: {
        ...(search.value.trim() ? { q: search.value.trim() } : {}),
        status: tab.value === "Faol" ? "active" : "all",
        ...(session.branch ? { branch_id: session.branch } : {}),
      },
    });
    downloadBlob(r.data, "bemorlar.csv");
  } catch (e) {
    if (!axios.isCancel(e)) notice.value = errorText(e);
  }
}
watch([search, tab, size], () => {
  page.value = 1;
});
</script>
<template>
<section class="patients"><ApiState :loading="loading||detailLoading" :error="error" @retry="load"/>
 <header class="patients-header"><div><h1>Bemorlar</h1><p>Barcha bemorlar ro‘yxati va asosiy ma’lumotlari</p></div><div class="patients-head-actions"><div class="patients-total"><Users :size="23"/><span>Jami bemorlar:<strong>{{loading||error?'—':total}}</strong></span></div><button class="patients-primary" v-if="can('patients.create')" @click="create"><Plus :size="20"/> Yangi bemor</button></div></header>
 <div v-if="notice" class="patients-notice" role="status">{{notice}} <button @click="notice=''" aria-label="Yopish"><X :size="16"/></button></div>
 <div class="patients-filterbar"><label class="patients-search"><Search :size="19"/><input v-model="search" maxlength="100" @input="page=1" placeholder="F.I.Sh., bemor raqami yoki telefon..."/></label><label class="patients-filter"><small>Jins</small><select disabled title="API filtri mavjud emas" v-model="gender" @change="page=1"><option value="">Barchasi</option><option>Ayol</option><option>Erkak</option></select></label><label class="patients-filter"><small>Holat</small><select disabled title="API filtri mavjud emas" v-model="status" @change="page=1"><option value="">Barchasi</option><option>Faol</option><option disabled>Nazoratda — API mavjud emas</option><option disabled>Yangi — API mavjud emas</option></select></label><label class="patients-filter"><small>Oxirgi tashrif</small><select disabled title="API filtri mavjud emas" v-model="visitRange" @change="page=1"><option value="">Barchasi</option><option value="7">So‘nggi 7 kun</option><option value="30">So‘nggi 30 kun</option><option value="old">30 kundan oldin</option></select></label><label class="patients-filter"><small>Filial</small><select aria-label="Filial filtri" v-model="branch" @change="page=1"><option value="">Barchasi</option><option v-for="b in session.branches" :key="b.id" :value="b.id">{{b.name}}</option></select></label><button class="patients-export" v-if="can('patients.export')" @click="exportCsv"><Download :size="18"/> Eksport</button></div>
 <div class="patients-panel"><nav class="patients-tabs"><button v-for="t in tabs" :key="t" :class="{active:tab===t}" :disabled="['Yangi','Nazoratda'].includes(t)" :title="['Yangi','Nazoratda'].includes(t)?'Bu holat API’da mavjud emas':''" @click="tab=t;page=1">{{t}} <span>{{count(t)}}</span></button></nav>
 <div class="patients-table-wrap"><table><thead><tr><th>#</th><th>ID</th><th>F.I.Sh.</th><th>Telefon</th><th>Tug‘ilgan sana</th><th>Jinsi</th><th>Oxirgi tashrif</th><th>Tashriflar soni</th><th>Holati</th><th>Amallar</th></tr></thead><tbody><tr v-for="(p,i) in visible" :key="p.id"><td>{{(currentPage-1)*size+i+1}}</td><td class="patients-muted">{{p.patient_number}}</td><td><div class="patients-person"><span class="patients-avatar">{{p.name.split(' ').map(s=>s[0]).join('').slice(0,2)}}</span><div><strong>{{p.name}}</strong><small>{{p.patient_number}}</small></div></div></td><td class="patients-muted">{{p.phone||'—'}}</td><td>{{p.dob}}<small v-if="p.dob" class="patients-sub">({{age(p.dob)}} yosh)</small></td><td><span class="patients-gender" :class="p.gender">{{p.gender}}</span></td><td>{{p.last||'—'}}<small class="patients-sub">Tashrif statistikasi API’da yo‘q</small></td><td>{{p.visits}}</td><td><span class="patients-status" :class="p.status">● {{p.status}}</span></td><td><div class="patients-actions"><button class="view" @click="open(p,'view')"><Eye :size="14"/> Ko‘rish</button><button v-if="can('patients.update')&&!p.archived_at" @click="edit(p)"><Pencil :size="14"/> Tahrirlash</button><button class="dots" aria-label="Batafsil" @click="open(p,'view')"><MoreHorizontal :size="18"/></button></div></td></tr><tr v-if="!loading&&!error&&!visible.length"><td colspan="10" class="patients-empty">Bemor topilmadi.</td></tr></tbody></table></div>
 <footer class="patients-footer"><div>Sahifada: <select v-model.number="size" @change="page=1"><option :value="5">5</option><option :value="10">10</option><option :value="20">20</option></select><span>{{total?(currentPage-1)*size+1:0}}–{{Math.min(currentPage*size,total)}} dan {{total}} ta bemor</span></div><div class="patients-pages"><button :disabled="loading||currentPage===1" @click="page--"><ChevronLeft :size="16"/> Oldingi</button><button v-for="i in pageNumbers" :key="i" :class="{active:i===currentPage}" @click="page=i">{{i}}</button><button :disabled="loading||currentPage>=totalPages" @click="page++">Keyingi <ChevronRight :size="16"/></button></div></footer></div>
 <div v-if="selected||mode==='create'" class="patients-overlay" @click.self="!saving&&close()"><div class="patients-modal" role="dialog" aria-modal="true"><header><h2>{{mode==='create'?'Yangi bemor':mode==='edit'?'Bemorni tahrirlash':'Bemor ma’lumotlari'}}</h2><button :disabled="saving" @click="close" aria-label="Yopish"><X :size="20"/></button></header><p v-if="notice" class="patients-notice" role="alert">{{notice}}</p><form v-if="mode!=='view'" @submit.prevent="save"><label>F.I.Sh. *<input v-model="form.name" maxlength="255" required/></label><label>Telefon<input v-model="form.phone" maxlength="32"/></label><label>Tug‘ilgan sana *<input v-model="form.dob" type="date" required/></label><label>Jinsi<select v-model="form.gender"><option>Ayol</option><option>Erkak</option><option value="—">Ko‘rsatilmagan</option></select></label><label v-if="mode==='create'">Filial<select aria-label="Filial" required v-model="form.branch"><option value="">Filialni tanlang</option><option v-for="b in session.branches" :key="b.id" :value="b.id">{{b.name}}</option></select></label><div class="patients-modal-footer"><button :disabled="saving" type="button" @click="close">Bekor qilish</button><button :disabled="saving" class="patients-primary" type="submit">Saqlash</button></div></form><div v-else-if="selected" class="patients-details"><p><b>ID:</b> {{selected.patient_number}}</p><p><b>F.I.Sh.:</b> {{selected.name}}</p><p><b>Telefon:</b> {{selected.phone}}</p><p><b>Tug‘ilgan sana:</b> {{selected.dob}}</p><p><b>Jinsi:</b> {{selected.gender}}</p><p><b>Oxirgi tashrif:</b> {{selected.last||'—'}}</p><p><b>Tashriflar:</b> {{selected.visits}}</p><p><b>Holati:</b> {{selected.status}}</p><p><b>Filial:</b> {{selected.branch}}</p><button class="patients-primary" v-if="can('patients.update')&&!selected.archived_at" @click="edit(selected)">Tahrirlash</button></div></div></div>
</section>
</template>

<style scoped>
.patients{font-family:Inter,Arial,sans-serif;color:#102045;width:100%;min-width:0}.patients *{box-sizing:border-box}.patients button,.patients input,.patients select{font:inherit}.patients button{cursor:pointer}.patients-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px}.patients-header h1{font-size:34px;line-height:1.15;margin:0 0 7px}.patients-header p{margin:0;color:#6480aa;font-size:15px}.patients-head-actions{display:flex;align-items:center;gap:12px}.patients-total{display:flex;align-items:center;gap:12px;background:#eff6ff;border-radius:9px;padding:10px 16px;color:#1671f9}.patients-total span{display:flex;flex-direction:column;gap:4px;color:#547099;font-size:12px;white-space:nowrap}.patients-total strong{color:#102045;font-size:22px}.patients-primary{display:inline-flex;align-items:center;justify-content:center;gap:9px;border:0;border-radius:8px;background:#176dff;color:white;padding:14px 18px;white-space:nowrap;font-size:13px;font-weight:700}.patients-notice{display:flex;justify-content:space-between;background:#edf5ff;border:1px solid #cedfff;color:#2169b8;border-radius:8px;padding:10px;margin-bottom:12px}.patients-notice button{background:none;border:0;color:inherit}.patients-filterbar,.patients-panel{background:#fff;border:1px solid #e1e9f7;border-radius:12px}.patients-filterbar{padding:16px;display:grid;grid-template-columns:minmax(220px,2.5fr) repeat(4,minmax(90px,1fr)) auto;gap:11px;align-items:center;margin-bottom:14px}.patients-search{display:flex;align-items:center;gap:9px;min-width:0;height:42px;padding:0 11px;border:1px solid #dfe8f6;background:#f7faff;border-radius:7px;color:#8da5c7}.patients-search input{outline:0;border:0;background:transparent;width:100%;min-width:0;font-size:12px;color:#102045}.patients-filter{position:relative;min-width:0}.patients-filter small{position:absolute;top:-8px;left:9px;background:white;padding:0 4px;color:#8096b9;font-size:10px}.patients-filter select{height:42px;width:100%;min-width:0;border:1px solid #e1e9f7;border-radius:7px;background:white;padding:0 7px;color:#38547c;font-size:11px}.patients-export{height:42px;display:flex;align-items:center;gap:8px;padding:0 12px;border:1px solid #e1e9f7;border-radius:7px;background:white;color:#193359;font-size:12px}.patients-export svg{color:#176dff}.patients-panel{padding:0 14px 12px;min-width:0}.patients-tabs{display:flex;gap:16px;border-bottom:1px solid #e9eff9;overflow-x:auto}.patients-tabs button{border:0;border-bottom:2px solid transparent;background:none;padding:15px 8px;font-size:12px;white-space:nowrap;color:#41618e}.patients-tabs button.active{border-color:#1671ff;color:#1671ff;font-weight:700}.patients-tabs span{background:#eef4ff;border-radius:5px;padding:3px 6px;margin-left:5px}.patients-table-wrap{overflow-x:auto;width:100%}.patients table{border-collapse:collapse;width:100%;min-width:1020px;text-align:left;font-size:11px}.patients th{background:#f3f7fc;color:#59739a;font-size:11px;font-weight:600;padding:13px 9px;white-space:nowrap}.patients td{padding:9px;border-bottom:1px solid #e9eff8;white-space:nowrap}.patients-muted,.patients-sub{color:#708bb2}.patients-sub{display:block;font-size:10px;margin-top:5px}.patients-person{display:flex;align-items:center;gap:9px}.patients-person strong{font-size:11px;display:block}.patients-person small{display:block;margin-top:5px;font-size:10px;color:#7791b5}.patients-avatar{height:38px;width:38px;flex:none;border-radius:50%;background:linear-gradient(135deg,#e5eefa,#f9eade);display:grid;place-items:center;color:#45698e}.patients-gender,.patients-status{display:inline-flex;border-radius:6px;padding:8px 10px;font-size:11px}.patients-gender.Ayol{background:#fff0f5;color:#e7377f}.patients-gender.Erkak{background:#eaf4ff;color:#146df6}.patients-status.Faol{background:#e5f8ee;color:#00a979}.patients-status.Nazoratda{background:#fff2e2;color:#e58700}.patients-status.Yangi{background:#eaf4ff;color:#146df6}.patients-actions{display:flex;align-items:center;gap:6px}.patients-actions button{display:flex;align-items:center;gap:5px;border:1px solid #e2ebf9;border-radius:7px;background:white;color:#176dff;padding:8px 9px;font-size:10px}.patients-actions .view{background:#eaf4ff}.patients-actions .dots{padding:7px}.patients-empty{text-align:center;padding:30px!important;color:#7e93b0}.patients-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding-top:16px;font-size:12px;color:#547099}.patients-footer>div:first-child,.patients-pages{display:flex;align-items:center;gap:7px}.patients-footer>div:first-child span{margin-left:10px}.patients-footer select,.patients-pages button{border:1px solid #e0eafa;border-radius:7px;background:white;color:#36517a;height:35px;padding:0 10px}.patients-pages button{display:flex;align-items:center;justify-content:center;gap:4px;min-width:34px;font-size:11px}.patients-pages button.active{background:#176dff;color:white;border-color:#176dff}.patients-pages button:disabled{opacity:.4;cursor:not-allowed}.patients-overlay{position:fixed;inset:0;background:#14274a80;z-index:1000;display:grid;place-items:center;padding:20px}.patients-modal{width:min(470px,100%);max-height:90vh;overflow:auto;background:white;border-radius:12px;box-shadow:0 18px 65px #0b18343d}.patients-modal header{display:flex;justify-content:space-between;align-items:center;padding:20px 23px;border-bottom:1px solid #e1e9f7}.patients-modal h2{margin:0;font-size:19px}.patients-modal header button{border:0;background:none;color:#537198}.patients-modal form,.patients-details{display:flex;flex-direction:column;gap:14px;padding:22px 23px}.patients-modal form label{display:flex;flex-direction:column;gap:7px;font-size:12px;color:#476186;font-weight:600}.patients-modal form input,.patients-modal form select{border:1px solid #dce6f5;border-radius:7px;padding:10px;background:white;color:#142b52}.patients-modal-footer{display:flex;justify-content:flex-end;gap:8px}.patients-modal-footer button{border:1px solid #dce6f5;border-radius:7px;padding:10px 16px;background:white}.patients-modal-footer .patients-primary{background:#176dff;color:white}.patients-details p{margin:0;font-size:13px;color:#4f6b90}.patients-details b{color:#102045}.patients-details .patients-primary{align-self:flex-start}@media(max-width:1450px){.patients-filterbar{grid-template-columns:minmax(220px,2fr) repeat(2,minmax(110px,1fr))}}@media(max-width:830px){.patients-header{flex-wrap:wrap}.patients-filterbar{grid-template-columns:repeat(2,minmax(0,1fr))}.patients-search{grid-column:1/-1}}@media(max-width:520px){.patients-filterbar{grid-template-columns:1fr}.patients-search{grid-column:auto}.patients-pages{flex-wrap:wrap}}
</style>
