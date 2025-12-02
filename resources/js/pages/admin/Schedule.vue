<script setup>
import { Head, usePage, router } from '@inertiajs/vue3';
import { computed, ref, watch, nextTick } from 'vue';
import axios from 'axios';

const user = usePage().props.auth.user;

if (user.isAdmin !== 1) {
    router.visit('/'); // не админ — уходим
}

// ---- утилиты ----
const pad = n => String(n).padStart(2, '0');

/**
 * Возвращает разницу в часах (с минутами, как дробь),
 * например 7.27 ч и т.п. Ничего не округляем здесь.
 */
function hoursBetween(start, end) {
    if (!start || !end) return 0;

    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);

    let startInMinutes = sh * 60 + sm;
    let endInMinutes   = eh * 60 + em;

    // если конец меньше/равен началу — это переход через полночь
    if (endInMinutes <= startInMinutes) {
        endInMinutes += 24 * 60;
    }

    const totalMinutes = endInMinutes - startInMinutes;
    return totalMinutes / 60;
}

function monthDays(year, monthIndex) { // monthIndex: 0–11
    return new Date(year, monthIndex + 1, 0).getDate();
}

const ru = {
    months:    ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'],
    monthsGen: ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'],
};

// ---- props из Laravel / Inertia ----
const page  = usePage();

// локальное состояние, синхронизируемое с props
const year  = ref(page.props.year);       // число, например 2025
const month = ref(page.props.month);      // 0–11 (Laravel передаёт $month - 1)

const staff       = ref(page.props.staff ?? []);          // [{id,name,role,department,fte,baseNorm}]
const departments = ref(page.props.departments ?? []);    // ['Терапия', ...]
const roles       = ref(page.props.roles ?? []);          // ['Медсестра', ...]
const shifts      = ref(page.props.shifts ?? {});         // { [userId]: { [day]: {type,start,end,post} } }
const swaps       = ref(page.props.swaps ?? []);

// если Inertia вернёт новые props (другой месяц) — обновляем локальные ref
watch(
    () => page.props.staff,
    v => { staff.value = v ?? []; }
);
watch(
    () => page.props.departments,
    v => { departments.value = v ?? []; }
);
watch(
    () => page.props.roles,
    v => { roles.value = v ?? []; }
);
watch(
    () => page.props.shifts,
    v => { shifts.value = v ?? {}; }
);
watch(
    () => page.props.swaps,
    v => { swaps.value = v ?? []; }
);

// ---- фильтры, дни ----
const filters     = ref({ department: '', role: '' });
const daysInMonth = computed(() => monthDays(year.value, month.value));

// ---- работа с ячейками ----
function rowCells(row) {
    if (!shifts.value[row.id]) {
        shifts.value[row.id] = {};
    }
    return shifts.value[row.id];
}

function defaultTimesByType(type) {
    switch (type) {
        case '8h':         return { start: '08:00', end: '16:00' };
        case '12h':        return { start: '08:00', end: '20:00' };
        case '12n':        return { start: '20:00', end: '08:00' };
        case '15h':        return { start: '09:00', end: '24:00' };
        case '24h':        return { start: '08:00', end: '08:00' };
        case 'home_day':   return { start: '12:00', end: '24:00' };
        case 'home_night': return { start: '00:00', end: '08:00' };

        // твои дополнительные типы с минутами
        case '7:12h':  return { start: '12:48', end: '20:00' };
        case '7-12h':  return { start: '08:00', end: '15:12' };
        case '6h':     return { start: '08:00', end: '14:00' };
        case '8:18h':  return { start: '08:00', end: '16:18' };
        case '8-18h':  return { start: '11:42', end: '20:00' };
        case '10h':    return { start: '14:00', end: '00:00' };
        case '8-h':    return { start: '00:00', end: '08:00' };
        case '16h':    return { start: '08:00', end: '00:00' };
        case '7:36h':  return { start: '16:18', end: '00:00' };
        default:       return { start: '', end: '' };
    }
}

function shortLabel(row, day) {
    const c = rowCells(row)[day];
    if (!c || !c.type) return '';
    if (c.type === '12n') return '12ч (ночь)';
    if (c.type.startsWith('home')) return 'дом.';
    return c.type.replace('h', 'ч');
}

function timeLabel(row, day) {
    const c = rowCells(row)[day];
    return c && c.start ? `${c.start}–${c.end}` : '';
}

/**
 * Сколько часов (с дробью) в одной ячейке.
 */
function hoursForCell(c) {
    if (!c || !c.type) return 0;

    if (c.type === '24h') return 24; // полный сутки

    const start = c.start || defaultTimesByType(c.type).start;
    const end   = c.end   || defaultTimesByType(c.type).end;

    if (!start || !end) return 0;

    return hoursBetween(start, end);
}

/**
 * Сколько часов / человека за месяц (с точностью до 0.1 ч).
 */
function plannedFor(row) {
    const rc = rowCells(row);
    let s = 0;
    for (let d = 1; d <= daysInMonth.value; d++) {
        s += hoursForCell(rc[d]);
    }
    return Math.round(s * 10) / 10; // одна цифра после запятой
}

/**
 * Норма с учётом FTE, тоже округляем до 0.1 ч.
 */
function normFor(row) {
    const base = Number(row.baseNorm || 0);
    const fte  = Number(row.fte || 0);
    return Math.round(base * fte * 10) / 10;
}

function balanceFor(row) {
    const val = normFor(row) - plannedFor(row);
    return Math.round(val * 10) / 10;
}

/**
 * Проверка нарушения отдыха (тут можно считать по часам, не по минутам).
 */
function restConflict(row, day) {
    const rc   = rowCells(row);
    const cur  = rc[day];
    if (!cur || !cur.type) return false;
    const prev = rc[day - 1];
    if (!prev || !prev.type) return false;

    const prevStart = prev.start || defaultTimesByType(prev.type).start;
    const prevEnd   = prev.end   || defaultTimesByType(prev.type).end;
    const curStart  = cur.start  || defaultTimesByType(cur.type).start;

    const prevLenHours = hoursBetween(prevStart, prevEnd);
    const restH =
        (24 - prevLenHours) + // от конца прошлой смены до полуночи
        hoursBetween('00:00', curStart); // от полуночи до начала текущей

    return restH < 12;
}

function cellColor(row, day) {
    const rc = rowCells(row)[day];

    // ПУСТАЯ ЯЧЕЙКА — ЯРКАЯ
    if (!rc || !rc.type) {
        return 'bg-pink-300 border-pink-500';
    }

    if (rc.type === 'home_day' || rc.type === 'home_night') {
        return 'bg-amber-50 border-amber-200';
    }
    if (restConflict(row, day)) {
        return 'bg-rose-50 border-rose-300';
    }
    return 'bg-emerald-50 border-emerald-200';
}

function mobileChipColor(row, day) {
    const rc = rowCells(row)[day];

    if (!rc || !rc.type) {
        return 'bg-pink-300 border-pink-500 text-pink-950';
    }

    if (rc.type === 'home_day' || rc.type === 'home_night') {
        return 'bg-amber-50 border-amber-200 text-amber-800';
    }
    if (restConflict(row, day)) {
        return 'bg-rose-50 border-rose-200 text-rose-800';
    }
    return 'bg-emerald-50 border-emerald-200 text-emerald-800';
}

// ---- фильтрация сотрудников ----
const filteredRows = computed(() =>
    staff.value.filter(s =>
        (!filters.value.department || s.department === filters.value.department) &&
        (!filters.value.role        || s.role       === filters.value.role)
    )
);

// ---- KPI ----
const kpiSignificance = computed(() =>
    filteredRows.value.reduce((a, r) => a + Number(r.fte || 0), 0)
);
const kpiNorm = computed(() =>
    filteredRows.value.reduce((a, r) => a + normFor(r), 0)
);
const kpiPlanned = computed(() =>
    filteredRows.value.reduce((a, r) => a + plannedFor(r), 0)
);
const kpiBalance = computed(() => {
    const val = kpiNorm.value - kpiPlanned.value;
    return Math.round(val * 10) / 10;
});

// ---- модалка смены ----
const modal = ref({
    open: false,
    row: null,
    day: null,
    form: { type: '', start: '', end: '', post: '' },
});
const suppressTypeWatch = ref(false);

function openCell(row, day) {
    const rc = rowCells(row)[day] || {};
    suppressTypeWatch.value = true;
    modal.value.open = true;
    modal.value.row  = row;
    modal.value.day  = day;
    modal.value.form = {
        type:  rc.type  || '',
        start: rc.start || '',
        end:   rc.end   || '',
        post:  rc.post  || '',
    };
    nextTick(() => {
        suppressTypeWatch.value = false;
    });
}

// автоподстановка времени при выборе типа смены
watch(
    () => modal.value.form.type,
    (type, oldType) => {
        if (suppressTypeWatch.value) return;

        const form = modal.value.form;

        if (!type) {
            form.start = '';
            form.end   = '';
            return;
        }

        const { start, end } = defaultTimesByType(type);

        if (oldType && (form.start || form.end)) {
            form.start = start;
            form.end   = end;
            return;
        }

        if (!form.start && !form.end) {
            form.start = start;
            form.end   = end;
        }
    }
);

// сохраняем ячейку в БД + локально
async function saveCell() {
    const m  = modal.value;
    const rc = rowCells(m.row);
    let { type, start, end, post } = m.form;

    if (type && (!start || !end)) {
        const d = defaultTimesByType(type);
        start = start || d.start;
        end   = end   || d.end;
    }

    rc[m.day] = type ? { type, start, end, post } : {};
    modal.value.open = false;

    try {
        await axios.post('/admin/schedule/cell', {
            user_id: m.row.id,
            year:    year.value,
            month:   month.value + 1,  // бек ждёт 1–12
            day:     m.day,
            type,
            start,
            end,
            post,
        });
        toast('Изменения сохранены');
    } catch (e) {
        toast('Ошибка сохранения ячейки');
    }
}

async function clearCell() {
    const m  = modal.value;
    const rc = rowCells(m.row);

    rc[m.day] = {};
    modal.value.open = false;

    try {
        await axios.post('/admin/schedule/cell-clear', {
            user_id: m.row.id,
            year:    year.value,
            month:   month.value + 1,
            day:     m.day,
        });
        toast('Ячейка очищена');
    } catch (e) {
        toast('Ошибка очистки ячейки');
    }
}

// ---- навигация по месяцам ----
function prevMonth() {
    if (month.value === 0) {
        month.value = 11;
        year.value--;
    } else {
        month.value--;
    }
}

function nextMonth() {
    if (month.value === 11) {
        month.value = 0;
        year.value++;
    } else {
        month.value++;
    }
}

// при смене месяца / года запрашиваем данные заново
watch([year, month], ([y, m]) => {
    router.get('/admin/schedule', { year: y, month: m + 1 }, {
        preserveScroll: true,
        preserveState:  true,
        replace:        true,
    });
});

// ---- очистка месяца ----
async function clearMonth() {
    try {
        await axios.post('/admin/schedule/clear-month', {
            year:  year.value,
            month: month.value + 1,
        });
        shifts.value = {};
        toast('Месяц очищен');
    } catch (e) {
        toast('Ошибка очистки месяца');
    }
}

// ---- загрузка демо-данных ----
async function seedDemo(showToast = true) {
    try {
        await axios.post('/admin/schedule/seed-demo', {
            year:  year.value,
            month: month.value + 1,
        });
        router.get('/admin/schedule', { year: year.value, month: month.value + 1 }, {
            preserveScroll: true,
            preserveState:  false,
        });
        if (showToast) toast('Демо-данные загружены');
    } catch (e) {
        toast('Ошибка загрузки демо-данных');
    }
}

// ---- шаблон и массовое назначение (только фронт) ----
function openTemplate() {
    filteredRows.value.forEach((row, idx) => {
        const rc = rowCells(row);
        for (let d = 1; d <= daysInMonth.value; d++) {
            const wd = new Date(year.value, month.value, d).getDay();
            if (wd === 0) { rc[d] = {}; continue; }
            const isEven = ((d - 1) + idx) % 2 === 0;
            rc[d] = isEven
                ? { type: '12h', start: '08:00', end: '20:00', post: 'пост' }
                : { type: '12n', start: '20:00', end: '08:00', post: 'пост' };
        }
    });
    toast('Шаблон применён (только фронт)');
}

function openCreateMany() {
    const startD = 10;
    filteredRows.value.forEach(r => {
        const rc = rowCells(r);
        for (let d = startD; d < startD + 3; d++) {
            rc[d] = { type: '8h', start: '08:00', end: '16:00', post: 'процедурная' };
        }
    });
    toast('Массовое назначение (только фронт)');
}

// ---- drag-copy по строке ----
const drag = ref({ active: false, src: null });

function startDrag(row, day, ev) {
    drag.value = { active: true, src: { row, day } };
    ev.currentTarget.classList.add('drag-hover');
}

function dragOver(row, day, ev) {
    if (!drag.value.active || !drag.value.src) return;
    if (row.id !== drag.value.src.row.id) return;
    ev.currentTarget.classList.add('drag-hover');
}

function leaveDrag(ev) {
    ev.currentTarget.classList.remove('drag-hover');
}

function endDrag(row, day, ev) {
    const src = drag.value.src;
    if (src && src.row.id === row.id) {
        const s = rowCells(src.row)[src.day];
        if (s && s.type) {
            rowCells(row)[day] = { ...s };
        }
    }
    drag.value = { active: false, src: null };
    ev.currentTarget.classList.remove('drag-hover');
}

// ---- approve / export / import ----
function approveAll() { toast('График утверждён (демо)'); }

// экспорт в CSV/Excel (браузер скачает файл)
function exportSchedule() {
    const y = year.value;
    const m = month.value + 1;
    window.location = `/admin/schedule/export?year=${y}&month=${m}`;
}

// импорт CSV/Excel
const importBusy  = ref(false);
const importInput = ref(null);

function clickImport() {
    if (importBusy.value) return;
    importInput.value?.click();
}

async function handleImportChange(event) {
    const file = event.target.files?.[0];
    event.target.value = ''; // сброс, чтобы можно было выбрать тот же файл

    if (!file) return;

    importBusy.value = true;
    try {
        const formData = new FormData();
        formData.append('file', file);

        await axios.post('/admin/schedule/import', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        toast('Импорт выполнен');
        router.get('/admin/schedule', { year: year.value, month: month.value + 1 }, {
            preserveScroll: true,
            preserveState:  false,
        });
    } catch (e) {
        toast('Ошибка импорта');
    } finally {
        importBusy.value = false;
    }
}

// ---- АДМИН УТВЕРЖДАЕТ / ОТКЛОНЯЕТ ЗАЯВКИ НА ПОДМЕНУ ----
function swapStatusLabel(s) {
    if (s === 'await_colleagues') return 'Ждёт коллег';
    if (s === 'await_head')      return 'Ждёт старшую';
    if (s === 'approved')        return 'Утверждена';
    if (s === 'declined')        return 'Отклонена';
    return s;
}

function swapBadgeClass(s) {
    if (s === 'await_colleagues') return 'bg-amber-50 text-amber-700 border border-amber-200';
    if (s === 'await_head')      return 'bg-indigo-50 text-indigo-700 border border-indigo-200';
    if (s === 'approved')        return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
    if (s === 'declined')        return 'bg-slate-50 text-slate-500 border border-slate-200';
    return 'bg-slate-100 text-slate-600 border-slate-200';
}

async function approveSwap(swap) {
    try {
        await axios.post(`/admin/schedule/swaps/${swap.id}/approve`);
        swap.status     = 'approved';
        swap.head       = { id: page.props.auth.user.id, name: page.props.auth.user.name };
        swap.approvedAt = new Date().toLocaleString('ru-RU');
        toast('Заявка утверждена');
    } catch (e) {
        toast('Ошибка утверждения заявки');
    }
}

async function rejectSwap(swap) {
    try {
        await axios.post(`/admin/schedule/swaps/${swap.id}/decline`);
        swap.status = 'declined';
        toast('Заявка отклонена');
    } catch (e) {
        toast('Ошибка отклонения заявки');
    }
}

// ---- МОДАЛКА СОЗДАНИЯ НОВОГО СОТРУДНИКА ----
const createUserModal = ref({
    open: false,
    busy: false,
    form: {
        name: '',
        email: '',
        department: '',
        role: 'Медсестра',
        fte: 1,
        baseNorm: 168,
    },
    errors: {},
});

function openCreateUser() {
    createUserModal.value.open   = true;
    createUserModal.value.busy   = false;
    createUserModal.value.errors = {};
    createUserModal.value.form   = {
        name: '',
        email: '',
        department: '',
        role: 'Медсестра',
        fte: 1,
        baseNorm: 168,
    };
}

async function submitCreateUser() {
    const modal = createUserModal.value;
    modal.busy   = true;
    modal.errors = {};

    try {
        const payload = {
            name:           modal.form.name,
            email:          modal.form.email,
            department:     modal.form.department,
            role:           modal.form.role,
            fte:            modal.form.fte,
            standart_hours: modal.form.baseNorm,
        };

        const response = await axios.post('/admin/users', payload);

        if (response.data && response.data.user) {
            staff.value.push(response.data.user);
        } else {
            router.get('/admin/schedule', { year: year.value, month: month.value + 1 }, {
                preserveScroll: true,
                preserveState:  false,
            });
        }

        toast('Сотрудник создан');
        modal.open = false;
    } catch (e) {
        if (e.response?.data?.errors) {
            modal.errors = e.response.data.errors;
        }
        toast('Ошибка создания сотрудника');
    } finally {
        createUserModal.value.busy = false;
    }
}

// ---- тосты ----
const toasts = ref([]);

function toast(text) {
    const id = Math.random().toString(36).slice(2);
    toasts.value.push({ id, text });
    setTimeout(() => {
        toasts.value = toasts.value.filter(t => t.id !== id);
    }, 2400);
}
</script>

<template>
    <Head title="Панель планирования">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>

    <!-- TOPBAR -->
    <header class="bg-gradient-to-r from-indigo-600 via-violet-600 to-sky-600 text-white">
        <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="size-15 rounded-2xl bg-white/10 ring-1 ring-white/20 grid place-items-center overflow-hidden">
                    <a href="/dashboard">
                        <img class="admin-doctor" src="/img/admin/doctor-admin.jpg" alt="Панель администратора" />
                    </a>
                </div>
                <div class="space-y-0.5">
                    <div class="text-xs text-white/80">
                        Сотрудник: {{ page.props.auth.user.name }}
                    </div>
                    <div class="text-lg font-semibold tracking-tight">
                        Панель планирования
                    </div>
                    <div class="text-xs text-white/80">
                        Месяц: {{ ru.monthsGen[month] }} {{ year }}
                    </div>
                </div>
            </div>
            <div class="hidden md:flex items-center gap-2 text-sm">
                <button
                    @click="approveAll"
                    class="px-4 py-2 rounded-xl bg-white text-indigo-700 font-medium shadow-sm hover:bg-indigo-50"
                >
                    Утвердить график
                </button>
                <button
                    @click="exportSchedule"
                    class="px-4 py-2 rounded-xl bg-white/10 ring-1 ring-white/40 hover:bg-white/15"
                >
                    Экспорт Excel
                </button>
                <button
                    @click="clickImport"
                    class="px-4 py-2 rounded-xl bg-white/10 ring-1 ring-white/40 hover:bg-white/15 flex items-center gap-1"
                    :disabled="importBusy"
                >
                    <span v-if="importBusy" class="spinner"></span>
                    <span>Импорт Excel</span>
                </button>
                <input
                    ref="importInput"
                    type="file"
                    class="hidden"
                    accept=".csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                    @change="handleImportChange"
                >
            </div>
        </div>
    </header>

    <!-- CONTROLS -->
    <div class="bg-slate-50 border-b">
        <div class="max-w-7xl mx-auto w-full px-4 py-3 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-xl shadow-sm bg-white border overflow-hidden">
                    <button @click="prevMonth" class="px-3 py-2 text-slate-600 hover:bg-slate-50 text-sm">
                        ◀
                    </button>
                    <div class="flex items-center gap-1 px-3 py-2 border-l border-r text-sm">
                        <select v-model.number="month" class="bg-transparent focus:outline-none">
                            <option v-for="(m, idx) in ru.months" :key="idx" :value="idx">
                                {{ m }}
                            </option>
                        </select>
                        <span>•</span>
                        <input
                            type="number"
                            v-model.number="year"
                            class="w-20 bg-transparent focus:outline-none text-center"
                        />
                    </div>
                    <button @click="nextMonth" class="px-3 py-2 text-slate-600 hover:bg-slate-50 text-sm">
                        ▶
                    </button>
                </div>

                <div class="hidden md:flex items-center gap-2 ml-2 text-sm">
                    <label class="text-slate-600">Отделение</label>
                    <select v-model="filters.department" class="px-3 py-2 rounded-xl border bg-white">
                        <option value="">Все</option>
                        <option v-for="d in departments" :key="d" :value="d">{{ d }}</option>
                    </select>

                    <label class="text-slate-600 ml-2">Должность</label>
                    <select v-model="filters.role" class="px-3 py-2 rounded-xl border bg-white">
                        <option value="">Все</option>
                        <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- KPI + ЗАЯВКИ -->
    <div class="max-w-7xl mx-auto w-full px-4 py-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- KPI -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:col-span-2">
            <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100">
                <div class="text-xs font-medium text-slate-500 uppercase tracking-wide">
                    Значимость должностей
                </div>
                <div class="mt-1 text-2xl font-semibold text-slate-900">
                    {{ kpiSignificance.toFixed(2) }}
                </div>
                <div class="mt-1 text-xs text-slate-500">
                    Сумма FTE по отфильтрованным сотрудникам
                </div>
            </div>
            <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100">
                <div class="text-xs font-medium text-slate-500 uppercase tracking-wide">
                    Норма времени
                </div>
                <div class="mt-1 text-2xl font-semibold text-slate-900">
                    {{ kpiNorm.toFixed(1) }} ч
                </div>
                <div class="mt-1 text-xs text-slate-500">Сумма норм на месяц</div>
            </div>
            <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100">
                <div class="text-xs font-medium text-slate-500 uppercase tracking-wide">
                    План по сменам
                </div>
                <div class="mt-1 text-2xl font-semibold text-slate-900">
                    {{ kpiPlanned.toFixed(1) }} ч
                </div>
                <div class="mt-1 text-xs text-slate-500">Назначено сменами</div>
            </div>
            <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100">
                <div class="text-xs font-medium text-slate-500 uppercase tracking-wide">
                    Баланс
                </div>
                <div
                    class="mt-1 text-2xl font-semibold"
                    :class="kpiBalance >= 0 ? 'text-emerald-600' : 'text-rose-600'"
                >
                    {{ kpiBalance.toFixed(1) }} ч
                </div>
                <div class="mt-1 text-xs text-slate-500">
                    Баланс = Норма − План
                </div>
            </div>
        </div>

        <!-- ЗАЯВКИ НА ПОДМЕНУ -->
        <div class="bg-white rounded-2xl p-4 shadow-sm border max-h-[320px] overflow-auto">
            <div class="flex items-center justify-between mb-2">
                <h3 class="font-semibold text-sm">Заявки на подмену</h3>
                <span class="text-xs text-slate-500">
                    Всего: {{ swaps.length }}
                </span>
            </div>
            <div v-if="!swaps.length" class="text-sm text-slate-500">
                Заявок пока нет.
            </div>
            <div v-for="s in swaps" :key="s.id" class="py-2 border-b last:border-b-0 text-xs">
                <div class="flex items-center justify-between">
                    <div class="font-medium">
                        {{ s.dateLabel }} • {{ s.kindLabel }}
                    </div>
                    <span
                        class="px-2 py-0.5 rounded-full"
                        :class="swapBadgeClass(s.status)"
                    >
                        {{ swapStatusLabel(s.status) }}
                    </span>
                </div>
                <div class="mt-0.5 text-slate-600">
                    Просит: <b>{{ s.requester?.name }}</b>
                    <span v-if="s.responder">
                        • Подменяет: <b>{{ s.responder?.name }}</b>
                    </span>
                </div>
                <div class="mt-0.5 text-slate-500">
                    Тип заявки:
                    <span v-if="s.targetType === 'all'">общая</span>
                    <span v-else>личная</span>
                    <span v-if="s.note"> • {{ s.note }}</span>
                </div>
                <div class="mt-0.5 text-slate-500" v-if="s.head">
                    Утвердил(а): <b>{{ s.head.name }}</b>
                    <span v-if="s.approvedAt"> • {{ s.approvedAt }}</span>
                </div>
                <div class="mt-2 flex gap-2">
                    <button
                        v-if="s.status === 'await_head'"
                        @click="approveSwap(s)"
                        class="px-3 py-1.5 rounded-xl bg-emerald-600 text-white text-xs"
                    >
                        Утвердить
                    </button>
                    <button
                        v-if="s.status === 'await_head' || s.status === 'await_colleagues'"
                        @click="rejectSwap(s)"
                        class="px-3 py-1.5 rounded-xl border text-xs"
                    >
                        Отклонить
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- DESKTOP MATRIX -->
    <main class="max-w-7xl mx-auto w-full px-4 py-4 hidden lg:block">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-3 border-b border-slate-200 flex items-center justify-between">
                <div class="text-sm text-slate-600">
                    «Шахматка» — сотрудники × дни месяца
                </div>
                <div class="text-xs text-slate-500">
                    Клик — редактирование; перетаскивайте по строке для копирования смены
                </div>
            </div>

            <div class="overflow-auto max-h-[70vh]">
                <table class="min-w-full text-xs table-sticky-header">

                <thead>
                    <tr class="bg-slate-50 text-slate-600">
                        <th
                            class="col-sticky left-0 min-w-[280px] text-left px-3 py-2 border-r border-slate-200"
                        >
                            Сотрудник / Должность / FTE
                        </th>
                        <th
                            class="px-3 py-2 text-center border-b border-l border-slate-200 min-w-[120px]"
                        >
                            Итого (ч)
                        </th>
                        <th
                            class="px-3 py-2 text-center border-b border-l border-slate-200 min-w-[140px]"
                        >
                            Норма / Баланс
                        </th>
                        <th
                            v-for="d in daysInMonth"
                            :key="'h' + d"
                            class="px-2 py-2 text-center border-b border-l border-slate-200 min-w-[68px]"
                        >
                            {{ d }}
                        </th>
                    </tr>
                    </thead>

                    <tbody>
                    <tr
                        v-for="row in filteredRows"
                        :key="row.id"
                        class="odd:bg-white even:bg-slate-50/60"
                    >
                        <td class="col-sticky left-0 border-r border-slate-200 bg-white">
                            <div class="px-3 py-2">
                                <div class="font-medium text-slate-900">{{ row.name }}</div>
                                <div class="text-xs text-slate-500">
                                    Сотрудник • {{ row.department }}
                                </div>
                                <div class="text-xs text-slate-600">
                                    FTE: <b>{{ row.fte }}</b>
                                    • Норма: <b>{{ normFor(row).toFixed(1) }} ч</b>
                                </div>
                            </div>
                        </td>

                        <td class="text-center border-l border-slate-200">
                            <div class="px-2 py-2 font-semibold text-slate-900">
                                {{ plannedFor(row).toFixed(1) }}
                            </div>
                        </td>
                        <td class="text-center border-l border-slate-200">
                            <div class="px-2 py-2">
                                <span
                                    :class="
                                        balanceFor(row) >= 0
                                            ? 'text-emerald-600'
                                            : 'text-rose-600'
                                    "
                                >
                                    {{ normFor(row).toFixed(1) }} / {{ balanceFor(row).toFixed(1) }}
                                </span>
                            </div>
                        </td>

                        <td
                            v-for="d in daysInMonth"
                            :key="row.id + '-' + d"
                            class="cell border-l border-b border-slate-200 align-top cursor-pointer"
                            :class="cellColor(row, d)"
                            :data-day="d"
                            @mousedown="startDrag(row, d, $event)"
                            @mouseenter="dragOver(row, d, $event)"
                            @mouseup="endDrag(row, d, $event)"
                            @mouseleave="leaveDrag($event)"
                            @click="openCell(row, d)"
                        >
                            <div class="px-1.5 pt-1 leading-4 select-none">
                                <div class="font-medium text-[11px] text-slate-900">
                                    {{ shortLabel(row, d) }}
                                </div>
                                <div class="text-[10px] text-slate-600">
                                    {{ timeLabel(row, d) }}
                                </div>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="p-3 border-t border-slate-200 text-xs text-slate-500 space-x-3">
                <span class="inline-flex items-center gap-1">
                    <span class="inline-block w-3 h-3 rounded bg-emerald-50 border border-emerald-200"></span>
                    <span>назначено</span>
                </span>
                <span class="inline-flex items-center gap-1">
                    <span class="inline-block w-3 h-3 rounded bg-amber-50 border border-amber-200"></span>
                    <span>домашнее дежурство</span>
                </span>
                <span class="inline-flex items-center gap-1">
                    <span class="inline-block w-3 h-3 rounded bg-rose-50 border border-rose-300"></span>
                    <span>возможное нарушение отдыха</span>
                </span>
            </div>
        </div>
    </main>

    <!-- MOBILE -->
    <section class="lg:hidden px-4 py-4 space-y-3">
        <div class="flex gap-2">
            <select
                v-model="filters.department"
                class="flex-1 px-3 py-2 rounded-xl border bg-white text-sm"
            >
                <option value="">Все отделения</option>
                <option v-for="d in departments" :key="d" :value="d">
                    {{ d }}
                </option>
            </select>
            <select
                v-model="filters.role"
                class="flex-1 px-3 py-2 rounded-xl border bg-white text-sm"
            >
                <option value="">Все должности</option>
                <option v-for="r in roles" :key="r" :value="r">
                    {{ r }}
                </option>
            </select>
        </div>

        <div
            v-for="row in filteredRows"
            :key="'m' + row.id"
            class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden"
        >
            <div class="p-3 flex items-start justify-between gap-2">
                <div>
                    <div class="font-medium text-slate-900">{{ row.name }}</div>
                    <div class="text-xs text-slate-500">
                        {{ row.role }} • {{ row.department }}
                    </div>
                    <div class="mt-1 text-xs text-slate-600">
                        Норма: <b>{{ normFor(row).toFixed(1) }}</b> ч • План:
                        <b>{{ plannedFor(row).toFixed(1) }}</b> ч
                    </div>
                </div>
                <div
                    class="text-xs px-2 py-1 rounded-full whitespace-nowrap"
                    :class="
                        balanceFor(row) >= 0
                            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                            : 'bg-rose-50 text-rose-700 border border-rose-200'
                    "
                >
                    Баланс: {{ balanceFor(row).toFixed(1) }} ч
                </div>
            </div>
            <div class="p-3 pt-0">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="d in daysInMonth"
                        :key="'mb' + row.id + '-' + d"
                        class="px-2.5 py-1.5 rounded-xl text-xs border"
                        :class="mobileChipColor(row, d)"
                        @click="openCell(row, d)"
                    >
                        {{ d }} • {{ shortLabel(row, d) || '—' }}
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- MODAL СМЕНЫ -->
    <div v-if="modal.open" class="fixed inset-0 z-50">
        <div
            class="absolute inset-0 bg-slate-900/50"
            @click="modal.open = false"
        ></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-xl bg-white rounded-2xl shadow-soft border overflow-hidden">
                <div class="p-4 border-b flex items-center justify-between">
                    <div class="font-semibold text-slate-900">
                        Назначение смены — {{ modal.row.name }} ({{ modal.day }}
                        {{ ru.monthsGen[month] }})
                    </div>
                    <button
                        @click="modal.open = false"
                        class="text-slate-500 hover:text-slate-800"
                    >
                        ✕
                    </button>
                </div>
                <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <label class="text-slate-600">Тип смены</label>
                        <select
                            v-model="modal.form.type"
                            class="mt-1 w-full rounded-xl border px-3 py-2"
                        >
                            <option value="">— пусто —</option>
                            <option value="8h">8 часов</option>
                            <option value="12h">12 часов (08–20)</option>
                            <option value="12n">12 часов (20–08)</option>
                            <option value="15h">15 часов (09–24)</option>
                            <option value="24h">24 часа (08–08)</option>

                            <option value="7:12h">7 часов 12 минут (12:48–20:00)</option>
                            <option value="7-12h">7 часов 12 минут (08:00–15:12)</option>
                            <option value="6h">6 часов (08:00–14:00)</option>
                            <option value="8:18h">8 часов 18 минут (08:00–16:18)</option>
                            <option value="8-18h">8 часов 18 минут (11:42–20:00)</option>
                            <option value="10h">10 часов (14:00–24:00)</option>
                            <option value="8-h">8 часов (00:00–08:00)</option>
                            <option value="7:36h">7 часов 42 минуты (16:18–24:00)</option>
                            <option value="16h">16 часов (08:00–24:00)</option>

                            <option value="home_day">
                                Дежурство на дому (12–24)
                            </option>
                            <option value="home_night">
                                Дежурство на дому (00–08)
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="text-slate-600">
                            Пост / место
                        </label>
                        <input
                            v-model="modal.form.post"
                            placeholder="процедурная / пост 2"
                            class="mt-1 w-full rounded-xl border px-3 py-2"
                        >
                    </div>
                    <div>
                        <label class="text-slate-600">Начало</label>
                        <input
                            type="time"
                            v-model="modal.form.start"
                            class="mt-1 w-full rounded-xl border px-3 py-2"
                        >
                    </div>
                    <div>
                        <label class="text-slate-600">Окончание</label>
                        <input
                            type="time"
                            v-model="modal.form.end"
                            class="mt-1 w-full rounded-xl border px-3 py-2"
                        >
                    </div>
                    <div class="sm:col-span-2 text-xs text-slate-500 mt-1">
                        При выборе типа время подставится автоматически;
                        «24 часа» — 08:00→08:00+1.
                    </div>
                </div>
                <div class="p-4 border-t flex items-center justify-between">
                    <button @click="clearCell" class="px-3 py-2 rounded-xl border text-sm">
                        Очистить
                    </button>
                    <div class="space-x-2">
                        <button
                            @click="saveCell"
                            class="px-4 py-2 rounded-xl bg-brand-600 text-white text-sm"
                        >
                            Сохранить
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- МОДАЛКА СОЗДАНИЯ СОТРУДНИКА -->
    <div v-if="createUserModal.open" class="fixed inset-0 z-50">
        <div
            class="absolute inset-0 bg-slate-900/40"
            @click="createUserModal.open = false"
        ></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-lg bg-white rounded-2xl shadow-soft border overflow-hidden">
                <div class="p-4 border-b flex items-center justify-between">
                    <div class="font-semibold text-slate-900">Создать сотрудника</div>
                    <button
                        @click="createUserModal.open = false"
                        class="text-slate-500 hover:text-slate-800"
                    >
                        ✕
                    </button>
                </div>
                <div class="p-4 space-y-3 text-sm">
                    <div>
                        <label class="text-slate-600">ФИО</label>
                        <input
                            v-model="createUserModal.form.name"
                            class="mt-1 w-full rounded-xl border px-3 py-2"
                            placeholder="Иванова Мария Петровна"
                        >
                        <div v-if="createUserModal.errors.name" class="text-xs text-rose-600 mt-1">
                            {{ createUserModal.errors.name[0] }}
                        </div>
                    </div>
                    <div>
                        <label class="text-slate-600">Email (логин)</label>
                        <input
                            v-model="createUserModal.form.email"
                            class="mt-1 w-full rounded-xl border px-3 py-2"
                            type="email"
                            placeholder="nurse@example.com"
                        >
                        <div v-if="createUserModal.errors.email" class="text-xs text-rose-600 mt-1">
                            {{ createUserModal.errors.email[0] }}
                        </div>
                    </div>
                    <div>
                        <label class="text-slate-600">Отделение</label>
                        <input
                            v-model="createUserModal.form.department"
                            class="mt-1 w-full rounded-xl border px-3 py-2"
                            placeholder="Терапия"
                        >
                        <div v-if="createUserModal.errors.department" class="text-xs text-rose-600 mt-1">
                            {{ createUserModal.errors.department[0] }}
                        </div>
                    </div>
                    <div>
                        <label class="text-slate-600">Должность</label>
                        <input
                            v-model="createUserModal.form.role"
                            class="mt-1 w-full rounded-xl border px-3 py-2"
                            placeholder="Медсестра"
                        >
                        <div v-if="createUserModal.errors.role" class="text-xs text-rose-600 mt-1">
                            {{ createUserModal.errors.role[0] }}
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-slate-600">FTE (ставка)</label>
                            <input
                                v-model.number="createUserModal.form.fte"
                                type="number"
                                step="0.25"
                                min="0.25"
                                class="mt-1 w-full rounded-xl border px-3 py-2"
                            >
                        </div>
                        <div>
                            <label class="text-slate-600">Норма часов / мес</label>
                            <input
                                v-model.number="createUserModal.form.baseNorm"
                                type="number"
                                min="1"
                                class="mt-1 w-full rounded-xl border px-3 py-2"
                            >
                        </div>
                    </div>
                    <div class="text-xs text-slate-500">
                        Пароль можно задать/сменить в разделе настроек пользователя (на бэке).
                    </div>
                </div>
                <div class="p-4 border-t flex items-center justify-end gap-2">
                    <button
                        @click="createUserModal.open = false"
                        class="px-3 py-2 rounded-xl border text-sm"
                        :disabled="createUserModal.busy"
                    >
                        Отмена
                    </button>
                    <button
                        @click="submitCreateUser"
                        class="px-4 py-2 rounded-xl bg-brand-600 text-white text-sm flex items-center gap-2"
                        :disabled="createUserModal.busy"
                    >
                        <span v-if="createUserModal.busy" class="spinner"></span>
                        <span>Создать</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- TOASTS -->
    <div class="fixed bottom-4 right-4 space-y-2 z-[60]">
        <div
            v-for="t in toasts"
            :key="t.id"
            class="bg-slate-900 text-white/95 rounded-xl px-4 py-3 shadow-soft text-sm"
        >
            {{ t.text }}
        </div>
    </div>

    <footer class="py-8"></footer>
</template>

<style>
/* Липкая строка с датами в шапке шахматки */
.table-sticky-header thead tr {
    position: sticky;
    top: 0;
    z-index: 20;
    background: #f8fafc;
}

.col-sticky {
    position: sticky;
    left: 0;
    z-index: 10;
    background: white;
}

.drag-hover {
    outline: 2px dashed rgba(79, 70, 229, 0.6);
    outline-offset: -2px;
}
.cell {
    min-width: 68px;
    height: 54px;
}

/* кастомные цвета/кнопки */
.bg-mint-50 {
    background-color: #ecfdf5;
}
.bg-mint-100 {
    background-color: #d1fae5;
}
.bg-brand-600 {
    background-color: #4f46e5;
}
.bg-brand-50 {
    background-color: #eef2ff;
}
.bg-brand-50:hover {
    background-color: #e0e7ff;
}
.bg-rose-100 {
    background-color: #fee2e2;
}

.admin-doctor {
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: 18px;
}

.spinner {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid rgba(255,255,255,.6);
    border-top-color: #fff;
    animation: spin .8s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* лёгкая тень, чтобы не спорить с tailwind */
.shadow-soft {
    box-shadow: 0 18px 45px rgba(15, 23, 42, 0.16);
}
</style>
