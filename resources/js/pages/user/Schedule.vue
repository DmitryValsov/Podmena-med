<script setup>
import { Head, usePage, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

/* ==== базовые utils ==== */
const pad = n => String(n).padStart(2,'0');

// Локальное "чистое" YYYY-MM-DD без UTC-сдвигов
const toISO = (d) => {
    return [
        d.getFullYear(),
        pad(d.getMonth() + 1),
        pad(d.getDate())
    ].join('-');
};

const fromISO = (s) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d, 0, 0, 0, 0);
};

const addDays = (d,n)=>{ const x=new Date(d); x.setDate(x.getDate()+n); return x; }
const fmtTime = d => pad(d.getHours())+':'+pad(d.getMinutes());
const hexWithAlpha=(hex,a)=>{
    const h=hex?.replace('#','') || '94a3b8';
    const r=parseInt(h.slice(0,2),16),g=parseInt(h.slice(2,4),16),b=parseInt(h.slice(4,6),16);
    return`rgba(${r},${g},${b},${a})`
};
function cryptoId(){ return Math.random().toString(36).slice(2); }
function watchValue(src, cb){ let cur=src.value; setInterval(()=>{ if(src.value!==cur){ cur=src.value; cb(cur);} }, 120); }

/* ==== данные из Inertia ==== */
const page = usePage();
const user = page.props.auth.user;

// "я" — текущий пользователь
const me = {
    id: user.id,
    name: user.name,
    department: user.department_name ?? 'Отделение',
    avatar: user.avatar_url ?? 'https://i.pravatar.cc/100?img=48',
};

// коллеги
const colleagues = ref(page.props.colleagues || []);
const usersWithMe = ref([{ ...me, color:'#F15780' }, ...colleagues.value]);

// смены из БД (для текущей медсестры)
const shifts = ref(page.props.shifts || []);

// входящие заявки (из бэка) — добавляем локальный флаг busy
const incomingRequests = ref(
    (page.props.incomingRequests || []).map(r => ({ ...r, busy:false }))
);

// мои заявки (из бэка)
const requests = ref(page.props.mySwaps || []);

/* ==== календарь / недели ==== */
const today = new Date();

const selectedMobile = ref(toISO(today));
const monthLabel = computed(()=> fromISO(selectedMobile.value).toLocaleDateString(
    'ru-RU',{month:'long'}).replace(/^./,c=>c.toUpperCase())
);
const selectedMobileLabel = computed(() => {
    const d = fromISO(selectedMobile.value);
    const s = d.toLocaleDateString('ru-RU', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
    return s.charAt(0).toUpperCase() + s.slice(1);
});

function daysRange(anchor){
    const out=[];
    for(let i=-2;i<=4;i++){
        const d=addDays(anchor,i);
        out.push({
            key:d.toDateString(),
            date:toISO(d),
            day:d.getDate(),
            weekday:['Пн','Вт','Ср','Чт','Пт','Сб','Вс'][(d.getDay()+6)%7],
            isSelected:toISO(d)===selectedMobile.value
        });
    }
    return out;
}

const days = ref(daysRange(today));
function selectMobile(iso){ selectedMobile.value=iso; days.value=daysRange(fromISO(iso)); }
function shiftMobile(n){ selectMobile(toISO(addDays(fromISO(selectedMobile.value),n))); }
function goToday(){ selectMobile(toISO(today)); selectedDesktop.value=toISO(today); }

const scope = ref('mine');

function startOfWeek(d){
    const x=new Date(d);
    const day=(x.getDay()+6)%7;
    x.setDate(x.getDate()-day);
    x.setHours(0,0,0,0);
    return x;
}

const weekStart = ref(startOfWeek(today));
const weekDays = computed(()=>{
    const arr=[];
    const names=['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
    for(let i=0;i<7;i++){
        const d=addDays(weekStart.value,i);
        arr.push({
            date:d,
            key:d.toDateString(),
            weekday:names[i],
            label:`${pad(d.getDate())}.${pad(d.getMonth()+1)}`,
        });
    }
    return arr;
});

/* общий старт и масштаб для десктопа и мобилки */
const gridStartHour = ref(6);
const weekSlots = computed(()=>{
    const arr=[];
    for(let h=gridStartHour.value;h<=23;h++) arr.push(pad(h)+':00');
    return arr;
});
const rangeLabel = computed(()=>{
    const s=weekStart.value, e=addDays(s,6);
    return `${pad(s.getDate())}.${pad(s.getMonth()+1)}–${pad(e.getDate())}.${pad(e.getMonth()+1)}.${e.getFullYear()}`;
});
function weekShift(n){
    weekStart.value=addDays(weekStart.value,n*7);
    desktopRoster.value = buildWeekRoster(weekStart.value);
}

const selectedDesktop = ref(toISO(today));
function selectDayDesktop(d){ selectedDesktop.value = toISO(d); }

/* ==== построение расписания из БД ==== */

// смены для одного дня — для мобильного
function buildDayRoster(iso){
    return shifts.value
        .filter(s => s.date === iso)
        .map(s => {
            const start = s.start || '08:00';
            const end   = s.end   || '20:00';
            const [sh]  = start.split(':').map(Number);
            const part  = (sh >= 20 || sh < 8) ? 'night' : 'day';
            const kind  = s.type || '12h';

            return {
                id: s.id,
                date: iso,
                start,
                end,
                title: 'Смена • 1 мед.',
                place: s.post || 'пост',
                kind,
                part,
                assigned: 1,
                capacity: 1,
                isMine: true,
                line: '#80D2F9',
            };
        });
}

// смены на неделю — для десктопной «сетки»
function buildWeekRoster(weekStartDate){
    const events = [];
    for(let i=0;i<7;i++){
        const d   = addDays(weekStartDate,i);
        const iso = toISO(d);
        const dayShifts = shifts.value.filter(s => s.date === iso);

        dayShifts.forEach(s => {
            const startStr = s.start || '08:00';
            const endStr   = s.end   || '20:00';
            const [sh,sm]  = startStr.split(':').map(Number);
            const [eh,em]  = endStr.split(':').map(Number);

            const start_at = new Date(d.getFullYear(), d.getMonth(), d.getDate(), sh, sm || 0);
            let end_at     = new Date(d.getFullYear(), d.getMonth(), d.getDate(), eh, em || 0);
            if (end_at <= start_at) {
                end_at.setDate(end_at.getDate() + 1); // ночь через сутки
            }

            const kind = s.type || '12h';
            const part = (sh >= 20 || sh < 8) ? 'night' : 'day';

            events.push({
                id: s.id,
                date: iso,
                kind,
                part,
                start_at,
                end_at,
                capacity: 1,
                place: s.post || 'пост',
                nurses: [
                    { id: me.id, name: me.name, color:'#80D2F9' },
                ],
            });
        });
    }
    return events;
}

/* ==== расписание (state) ==== */

const mobileRoster  = ref(buildDayRoster(selectedMobile.value));
const desktopRoster = ref(buildWeekRoster(weekStart.value));

watchValue(selectedMobile, iso => {
    mobileRoster.value = buildDayRoster(iso);
});

const hoursPx = ref(64);
const nowTopPx = ref(null);

onMounted(() => {
    updateNowLine();

    // стата/следующая смена
    const todayISO = toISO(today);
    const given  = requests.value.length;
    const taken  = incomingRequests.value.filter(r => r.status === 'accepted').length;
    stats.value  = { given, taken };

    const futureShifts = shifts.value
        .filter(s => s.date >= todayISO)
        .sort((a,b) => a.date.localeCompare(b.date));

    if (futureShifts.length) {
        const s = futureShifts[0];
        nextShift.value = {
            date: s.date,
            shift: s.type || '12h',
            department: me.department,
        };
    }

    setInterval(updateNowLine, 60*1000);
});

function updateNowLine(){
    const now = new Date();
    const gridStartMin = gridStartHour.value*60;
    const last = parseInt(weekSlots.value.at(-1).slice(0,2),10)+1;
    const gridEndMin = last*60;
    const nowMin = now.getHours()*60 + now.getMinutes();
    if (nowMin<gridStartMin || nowMin>gridEndMin) { nowTopPx.value=null; return; }
    nowTopPx.value = (nowMin - gridStartMin) / 60 * hoursPx.value;
}

function isTodayFn(d){ return toISO(d)===toISO(today); }
function isSameISO(isoOrStr,d2){
    const a=typeof isoOrStr==='string'?isoOrStr:toISO(isoOrStr);
    return a===toISO(d2);
}

/* ==== события для UI ==== */

// для десктопной «шахматки»
function eventsForDay(date){
    const ds = toISO(date);
    const list = desktopRoster.value.filter(i => i.date===ds);
    const gridStartMin = gridStartHour.value * 60;
    const last = parseInt(weekSlots.value.at(-1).slice(0,2),10)+1;
    const gridEndMin   = last * 60;

    return list.map(i=>{
        const startMin = i.start_at.getHours()*60 + i.start_at.getMinutes();
        const endMin   = i.end_at.getHours()*60   + i.end_at.getMinutes();
        const clippedStart = Math.max(startMin, gridStartMin);
        const clippedEnd   = Math.min(endMin,   gridEndMin);
        if (clippedEnd <= clippedStart) return null;
        const topPx    = (clippedStart - gridStartMin) / 60 * hoursPx.value;
        const heightPx = (clippedEnd   - clippedStart) / 60 * hoursPx.value;
        const color = i.nurses[0]?.color || '#D1D5DB';
        const bg = hexWithAlpha(color, 0.18);
        const border = color;
        const time = `${fmtTime(i.start_at)}–${fmtTime(i.end_at)}`;
        const kindLabel = i.kind==='24h' ? '24 часа' : (i.part==='day'?'12ч (день)':'12ч (ночь)');
        return {
            id:i.id,
            top:`${topPx}px`,
            height:`${heightPx}px`,
            bg,
            border,
            title:`Смена`,
            time,
            kindLabel,
            place:i.place,
            assigned:i.nurses.length,
            capacity:i.capacity,
            swapCount:0,
            isMine: i.nurses.some(n=>n.id===me.id)
        };
    }).filter(Boolean);
}

// МОБИЛЬНАЯ вертикальная шкала
const mobileEvents = computed(() => {
    const gridStartMin = gridStartHour.value * 60;
    const gridEndMin   = 24 * 60; // до полуночи

    return mobileRoster.value.map(e => {
        const [sh, sm] = e.start.split(':').map(Number);
        const [eh, em] = e.end.split(':').map(Number);
        let startMin = sh*60 + (sm || 0);
        let endMin   = eh*60 + (em || 0);

        if (endMin <= startMin) endMin += 24*60; // ночь

        const clippedStart = Math.max(startMin, gridStartMin);
        const clippedEnd   = Math.min(endMin,   gridEndMin);
        if (clippedEnd <= clippedStart) return null;

        const topPx    = (clippedStart - gridStartMin) / 60 * hoursPx.value;
        const heightPx = (clippedEnd   - clippedStart) / 60 * hoursPx.value;
        const fill = e.assigned / e.capacity;
        const kindLabel = e.kind==='24h'
            ? '24ч (круглосуточно)'
            : (e.part==='day' ? '12ч (день)' : '12ч (ночь)');

        return {
            ...e,
            top: `${topPx}px`,
            height: `${heightPx}px`,
            kindLabel,
            fill,
            alert: e.assigned>=e.capacity
                ? 'мест нет'
                : (fill>=0.8 ? 'почти заполнено' : '')
        };
    }).filter(Boolean);
});

// только для счётчиков
function eventsAt(hour){
    return mobileRoster.value
        .filter(e => e.date===selectedMobile.value && e.start.slice(0,2)===hour.slice(0,2))
        .filter(e => scope.value==='mine' ? e.isMine : true);
}

const mineCount = computed(()=> mobileRoster.value
    .filter(e=>e.date===selectedMobile.value && e.isMine).length);
const deptCount = computed(()=> mobileRoster.value
    .filter(e=>e.date===selectedMobile.value).length);

/* ==== заявки коллег ==== */

const requestsScope = ref('day');

const visibleRequestsMobile = computed(()=>{
    return incomingRequests.value
        .filter(r => r.status==='pending' || r.status==='accepted')
        .filter(r => r.dateISO === selectedMobile.value)
        .map(addDateLabel);
});

const visibleRequestsDesktop = computed(()=>{
    const start = requestsScope.value==='day' ? fromISO(selectedDesktop.value): weekStart.value;
    const end   = requestsScope.value==='day' ? addDays(start,1) : addDays(weekStart.value,7);
    return incomingRequests.value
        .filter(r => r.status==='pending' || r.status==='accepted')
        .filter(r => {
            const d=fromISO(r.dateISO);
            return d>=start && d<end;
        })
        .map(addDateLabel);
});

function addDateLabel(r){
    return {
        ...r,
        dateLabel: new Date(r.dateISO).toLocaleDateString('ru-RU',{day:'2-digit',month:'long'})
    };
}

/* ==== работа с заявками коллег ==== */

async function acceptRequest(rq){
    if(rq.busy || rq.status!=='pending') return;
    const prevStatus = rq.status;
    rq.busy = true;
    rq.status = 'accepted';

    router.post(
        `/user/swap/${rq.id}/accept`,
        {},
        {
            preserveState: true,
            onError: () => {
                rq.status = prevStatus;
                rq.busy = false;
                toast('Ошибка при принятии заявки');
            },
            onSuccess: () => {
                rq.busy = false;
                toast('Отклик отправлен');
                router.visit('/user/schedule', {
                    preserveScroll: true,
                    replace: true,
                });
            },
        }
    );
}

async function declineRequest(rq){
    if(rq.busy || rq.status!=='pending') return;
    const prevStatus = rq.status;
    rq.busy = true;
    rq.status = 'declined';

    router.post(
        `/user/swap/${rq.id}/decline`,
        {},
        {
            preserveState: true,
            onError: () => {
                rq.status = prevStatus;
                rq.busy = false;
                toast('Ошибка при отказе от заявки');
            },
            onSuccess: () => {
                rq.busy = false;
                toast('Отказ отправлен');
                router.visit('/user/schedule', {
                    preserveScroll: true,
                    replace: true,
                });
            },
        }
    );
}

/* ==== Быстрая подмена (sidebar) ==== */
const minDate = toISO(today);
const quick = ref({
    date: toISO(today),
    shift:'12h',
    note:'',
    targetType:'all',
    search:'',
    targetUser:null,
});
const quickBusy = ref(false);
const directSearch = ref([]);

function filterColleagues(src){
    const q = (src==='modal'? swap.value.search : quick.value.search)
        .toLowerCase().trim();
    const list = !q ? [] : colleagues.value.filter(
        c=> (c.name+' '+c.department).toLowerCase().includes(q)
    );
    if (src==='modal') modalSearch.value = list;
    else directSearch.value = list;
}

function selectDirect(c){
    quick.value.targetUser=c;
    directSearch.value=[];
    quick.value.search='';
}

function submitQuick(){
    if(quick.value.targetType==='direct' && !quick.value.targetUser){
        toast('Выберите адресата для личной заявки'); return;
    }
    quickBusy.value = true;

    router.post(
        '/user/swap',
        {
            date: quick.value.date,
            shift: quick.value.shift,
            note: quick.value.note,
            target_type: quick.value.targetType,
            target_user_id: quick.value.targetUser?.id ?? null,
        },
        {
            preserveScroll: true,
            onError: () => {
                quickBusy.value = false;
                toast('Ошибка создания заявки');
            },
            onSuccess: () => {
                quickBusy.value = false;
                toast('Заявка создана');
                router.visit('/user/schedule', {
                    preserveScroll: true,
                    replace: true,
                });
            },
        }
    );
}

/* ==== "Мои заявки" ==== */
const requestsSorted = computed(() =>
    [...requests.value].sort((a,b)=> a.date.localeCompare(b.date))
);

function badgeClass(s){
    return s==='await_colleagues'
        ? 'bg-amber-100 text-amber-800 border border-amber-200'
        : (s==='await_head'
            ? 'bg-indigo-100 text-indigo-800 border-indigo-200'
            : (s==='approved'
                ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
                : 'bg-slate-100 text-slate-600 border-slate-200'));
}
function statusText(s){
    return s==='await_colleagues' ? 'Ждёт коллег'
        : s==='await_head' ? 'Ждёт старшую'
            : s==='approved' ? 'Утверждена'
                : 'Отклонена';
}
function shiftLabel(v){ return v==='12h'?'12 часов':'24 часа'; }
function fmt(s){
    const d=fromISO(s);
    return `${pad(d.getDate())}.${pad(d.getMonth()+1)}.${d.getFullYear()}`;
}

// демо-операции только на фронте
function demoAddOffer(r){
    r.offers=(r.offers||[]).concat({by:'Екатерина И.'});
    toast('Пришёл отклик (демо)');
}
function sendToHead(r){
    if(!r.offers?.length) return toast('Нет откликов');
    r.status='await_head';
    toast('Отправлено старшей (демо)');
}
function approveByHead(r){
    r.status='approved';
    toast('Подмена утверждена (демо)');
}
function revertRequest(r){
    r.status='await_colleagues';
    toast('Заявка возвращена (демо)');
}
function clearAllRequests(){
    requests.value=[];
}

/* ==== модалка "Мне нужна подмена" ==== */
const swap = ref({ open:false, ev:null, direct:false, search:'', target:null, busy:false });
const modalSearch = ref([]);

function openSwap(ev){
    swap.value={ open:true, ev, direct:false, search:'', target:null, busy:false };
}
async function sendSwap(type){
    if(type==='direct' && !swap.value.target){
        toast('Выберите адресата'); return;
    }
    swap.value.busy = true;

    router.post(
        '/user/swap',
        {
            date: selectedDesktop.value,
            shift: '12h',
            note: swap.value.ev ? `Подмена смены: ${swap.value.ev.time}` : '',
            target_type: type,
            target_user_id: type==='direct' ? swap.value.target.id : null,
        },
        {
            preserveScroll: true,
            onError: () => {
                swap.value.busy = false;
                toast('Ошибка отправки заявки');
            },
            onSuccess: () => {
                swap.value.busy = false;
                swap.value.open = false;
                toast(type==='all' ? 'Общая заявка отправлена' : 'Личная заявка отправлена');
                router.visit('/user/schedule', {
                    preserveScroll:true,
                    replace:true,
                });
            },
        }
    );
}

/* ==== стата и тосты ==== */
const stats = ref({ given:0, taken:0 });
const nextShift = ref({ date: toISO(today), shift:'12h', department: me.department });
const toasts = ref([]);
function toast(text){
    const id=cryptoId();
    toasts.value.push({id,text});
    setTimeout(()=> toasts.value = toasts.value.filter(t=>t.id!==id), 2600);
}
</script>

<template>
    <Head title="Расписание">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>

    <!-- HEADER -->
    <header class="bg-white/95 backdrop-blur border-b sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center gap-3">
            <a href="/dashboard">
                <img :src="me.avatar" class="w-10 h-10 rounded-full ring-2 ring-slate-100 object-cover" alt="">
            </a>
            <div class="min-w-0">
                <div class="text-xs text-slate-500 uppercase tracking-wide">Личный кабинет</div>
                <div class="font-semibold truncate">
                    {{ user.name }} • {{ me.department }}
                </div>
            </div>

            <!-- DESKTOP: основные действия -->
            <div class="ml-auto hidden md:flex items-center gap-2">
                <button
                    @click="openSwap(null)"
                    class="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium bg-brand-600 text-white shadow-soft hover:bg-indigo-700 transition"
                >
                    Мне нужна подмена
                </button>
                <button
                    @click="goToday"
                    class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm border bg-indigo-50 text-brand-600 hover:bg-indigo-100"
                >
                    Сегодня
                </button>
            </div>
        </div>

        <!-- MOBILE controls -->
        <div class="md:hidden px-4 pb-3 border-t border-slate-100">
            <div class="flex items-center gap-2">
                <button @click="shiftMobile(-1)" class="rounded-full border w-8 h-8 flex items-center justify-center text-lg text-slate-500">
                    ‹
                </button>
                <div class="font-semibold text-slate-900 truncate">
                    {{ monthLabel }}
                </div>
                <button @click="shiftMobile(1)" class="rounded-full border w-8 h-8 flex items-center justify-center text-lg text-slate-500">
                    ›
                </button>
                <button
                    @click="goToday"
                    class="ml-auto text-xs rounded-full border px-3 py-1 bg-indigo-50 text-brand-600"
                >
                    Сегодня
                </button>
            </div>

            <!-- полоса дней -->
            <div class="mt-2 flex gap-2 overflow-x-auto no-scrollbar">
                <button
                    v-for="d in days"
                    :key="d.key"
                    @click="selectMobile(d.date)"
                    class="min-w-[56px] py-2 rounded-2xl border flex flex-col items-center transition"
                    :class="d.isSelected
                        ? 'bg-brand-600 text-white border-brand-600'
                        : 'bg-slate-100 text-slate-700 border-slate-200'"
                >
                    <div class="text-[11px]">{{ d.weekday }}</div>
                    <div class="text-base font-semibold leading-none mt-0.5">{{ d.day }}</div>
                </button>
            </div>

            <!-- переключатель Мои / Отделение -->
            <div class="mt-3 grid grid-cols-2 gap-2">
                <button
                    @click="scope='mine'"
                    :class="scope==='mine' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'"
                    class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                >
                    Мои смены ({{ mineCount }})
                </button>
                <button
                    @click="scope='dept'"
                    :class="scope==='dept' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'"
                    class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                >
                    Всё отделение ({{ deptCount }})
                </button>
            </div>

            <!-- mobile CTA -->
            <button
                @click="openSwap(null)"
                class="mt-3 w-full inline-flex items-center justify-center gap-2 rounded-2xl px-4 py-2.5 text-sm font-semibold bg-brand-600 text-white shadow-soft hover:bg-indigo-700 transition"
            >
                Мне нужна подмена
            </button>
        </div>

        <!-- DESKTOP controls (неделя / зум) -->
        <div class="hidden md:block border-t">
            <div class="max-w-7xl mx-auto px-4 py-3 flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <button @click="weekShift(-1)" class="rounded-lg border px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
                        ◀
                    </button>
                    <div class="font-semibold text-slate-900">
                        {{ rangeLabel }}
                    </div>
                    <button @click="weekShift(1)" class="rounded-lg border px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
                        ▶
                    </button>
                </div>
                <div class="flex-1"></div>
                <div class="flex items-center gap-4 text-xs text-slate-500">
                    <div class="flex items-center gap-2">
                        <span>Старт:</span>
                        <input type="range" min="0" max="12" v-model.number="gridStartHour" class="w-28">
                        <span>{{ gridStartHour }}:00</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span>Зум (px/час):</span>
                        <input type="range" min="48" max="96" step="4" v-model.number="hoursPx" class="w-28" />
                        <span>{{ hoursPx }}</span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- CONTENT -->
    <main class="flex-1">
        <div class="max-w-7xl mx-auto md:grid md:grid-cols-3 gap-4 px-4">
            <!-- MOBILE расписание -->
            <section class="md:hidden col-span-2 pt-3">
                <div class="mb-1 pl-16 pr-3 text-sm font-semibold text-slate-900">
                    {{ selectedMobileLabel }}
                </div>

                <!-- моб. сетка времени -->
                <div class="mt-2 rounded-[32px] border border-slate-100 bg-slate-50 overflow-hidden relative">
                    <div>
                        <div
                            v-for="t in weekSlots"
                            :key="t"
                            class="flex items-start"
                            :style="{height: hoursPx + 'px'}"
                        >
                            <div class="w-16 text-right pr-3 text-[11px] text-slate-400 pt-1">
                                {{ t }}
                            </div>
                            <div class="flex-1 border-b border-slate-100"></div>
                        </div>
                    </div>

                    <!-- смены -->
                    <div class="absolute inset-y-0 left-16 right-3">
                        <div
                            v-for="ev in mobileEvents"
                            :key="ev.id"
                            class="absolute rounded-[28px] border px-3 py-2 shadow-sm flex items-start bg-sky-50 border-sky-200"
                            :style="{ top: ev.top, height: ev.height }"
                        >
                            <div class="w-1.5 h-full rounded-full bg-sky-400 mr-2"></div>
                            <div class="flex-1 min-w-0 flex flex-col">
                                <div class="flex items-center gap-2">
                                    <div class="font-medium text-slate-900 truncate">
                                        Смена • 1 мед.
                                    </div>
                                    <span class="text-[11px] text-slate-500 truncate">
                                        · {{ ev.place }}
                                    </span>
                                </div>
                                <div class="text-[11px] text-slate-600 mt-0.5">
                                    {{ ev.start }}–{{ ev.end }}
                                    · {{ ev.kindLabel }}
                                    · Пост: {{ ev.place }}
                                </div>

                                <div class="mt-auto flex items-center justify-between pt-1">
                                    <div class="text-[11px] flex items-center gap-3">
                                        <span
                                            class="inline-flex items-center gap-1"
                                            :class="ev.fill>=1
                                                ? 'text-rose-600'
                                                : ev.fill>=0.8
                                                    ? 'text-amber-600'
                                                    : 'text-emerald-600'"
                                        >
                                            👥 {{ ev.assigned }} / {{ ev.capacity }}
                                        </span>
                                        <span
                                            v-if="ev.alert"
                                            class="text-[11px] text-amber-700"
                                        >
                                            ⚠ {{ ev.alert }}
                                        </span>
                                    </div>
                                    <button
                                        v-if="ev.isMine"
                                        @click="openSwap(ev)"
                                        class="inline-flex items-center gap-1 text-[11px] rounded-full px-3 py-1 bg-amber-50 border border-amber-200 text-amber-800 font-medium"
                                    >
                                        Подмена
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- мобильные заявки коллег -->
                <div class="mt-4 rounded-2xl border bg-white p-3 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold text-sm">Запросы коллег на подмену</h3>
                        <span class="text-[11px] text-slate-500">общие + личные</span>
                    </div>
                    <div class="mt-2 divide-y">
                        <div
                            v-for="rq in visibleRequestsMobile"
                            :key="rq.id"
                            class="py-2 flex gap-3 items-start"
                        >
                            <div
                                class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center text-xs font-semibold text-slate-700"
                            >
                                {{ rq.name.slice(0,1) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium flex items-center gap-2">
                                    <span class="truncate">
                                        {{ rq.name }}
                                    </span>
                                    <span
                                        class="text-[10px] border rounded-full px-2 py-0.5"
                                        :class="rq.type==='all'
                                            ? 'bg-slate-50 text-slate-700 border-slate-200'
                                            : 'bg-indigo-50 text-brand-600 border-indigo-200'"
                                    >
                                        {{ rq.type==='all'?'общая':'личная' }}
                                    </span>
                                </div>
                                <div class="text-[11px] text-slate-500 mt-0.5">
                                    {{ rq.dateLabel }} • {{ rq.kindLabel }} • {{ rq.time }}
                                </div>
                                <div v-if="rq.note" class="text-xs mt-1 text-slate-700">
                                    {{ rq.note }}
                                </div>
                                <div class="mt-2 flex gap-2">
                                    <button
                                        @click="acceptRequest(rq)"
                                        :disabled="rq.busy || rq.status!=='pending'"
                                        class="inline-flex items-center gap-2 rounded-full bg-emerald-600 text-white px-3 py-1.5 text-xs font-medium disabled:opacity-50"
                                    >
                                        <span v-if="rq.busy" class="spinner"></span>
                                        <span>Подменить</span>
                                    </button>
                                    <button
                                        @click="declineRequest(rq)"
                                        :disabled="rq.busy || rq.status!=='pending'"
                                        class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs text-slate-700 disabled:opacity-50"
                                    >
                                        <span v-if="rq.busy" class="spinner spinner-dark"></span>
                                        <span>Отказаться</span>
                                    </button>
                                </div>
                                <div
                                    v-if="rq.status!=='pending'"
                                    class="mt-1 text-[11px]"
                                    :class="rq.status==='accepted'
                                        ? 'text-emerald-700'
                                        : 'text-slate-500'"
                                >
                                    {{ rq.status==='accepted'
                                    ? 'Вы откликнулись. Ждёт подтверждения.'
                                    : 'Вы отказались.' }}
                                </div>
                            </div>
                        </div>
                        <div v-if="!visibleRequestsMobile.length" class="py-3 text-sm text-slate-500">
                            На выбранную дату заявок нет.
                        </div>
                    </div>
                </div>
            </section>

            <!-- DESKTOP неделя -->
            <section class="hidden md:block md:col-span-2 py-4">
                <div class="border rounded-2xl bg-white overflow-hidden shadow-soft relative">
                    <!-- заголовок сетки -->
                    <div class="grid grid-cols-8 border-b bg-slate-50/90">
                        <div class="p-3 text-xs text-slate-500">Время</div>
                        <div
                            v-for="d in weekDays"
                            :key="d.key"
                            class="p-3 text-center cursor-pointer"
                            @click="selectDayDesktop(d.date)"
                        >
                            <div class="text-[11px] text-slate-500">{{ d.weekday }}</div>
                            <div
                                class="font-medium text-sm"
                                :class="isSameISO(selectedDesktop, d.date)
                                    ? 'text-brand-600 underline'
                                    : (isTodayFn(d.date) ? 'text-brand-600' : 'text-slate-800')"
                            >
                                {{ d.label }}
                            </div>
                        </div>
                    </div>

                    <!-- тело сетки -->
                    <div class="grid grid-cols-8">
                        <!-- колонка времени -->
                        <div class="border-r bg-slate-50/80">
                            <div
                                v-for="t in weekSlots"
                                :key="t"
                                class="grid-hour px-2 text-xs text-slate-500 flex items-start pt-1"
                                :style="{height: hoursPx + 'px'}"
                            >
                                {{ t }}
                            </div>
                        </div>

                        <!-- дни -->
                        <div
                            v-for="d in weekDays"
                            :key="d.key"
                            class="relative border-l last:border-r bg-slate-50/40"
                        >
                            <div class="pointer-events-none">
                                <div
                                    v-for="t in weekSlots"
                                    :key="t"
                                    class="grid-hour"
                                    :style="{height: hoursPx + 'px'}"
                                ></div>
                            </div>

                            <div
                                v-if="isTodayFn(d.date) && nowTopPx!==null"
                                class="now-line"
                                :style="{ top: nowTopPx + 'px' }"
                            ></div>

                            <div class="absolute inset-0">
                                <div
                                    v-for="ev in eventsForDay(d.date)"
                                    :key="ev.id"
                                    class="absolute left-1.5 right-1.5 event-card rounded-xl px-2.5 py-1.5 text-xs"
                                    :style="{
                                        top: ev.top,
                                        height: ev.height,
                                        backgroundColor: ev.bg,
                                        borderColor: ev.border
                                    }"
                                >
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="inline-block w-1.5 h-4 rounded-full"
                                            :style="{background: ev.border}"
                                        ></span>
                                        <div
                                            class="font-medium truncate"
                                            :class="ev.isMine ? 'text-slate-900' : 'text-slate-700'"
                                        >
                                            {{ ev.title }}
                                        </div>
                                        <button
                                            v-if="ev.isMine"
                                            @click="openSwap(ev)"
                                            class="ml-auto inline-flex items-center gap-1 text-[11px] rounded-full px-2.5 py-0.5 bg-amber-50 text-amber-800 border border-amber-200"
                                        >
                                            Подмена
                                        </button>
                                    </div>
                                    <div class="text-[11px] text-slate-600 mt-0.5 flex flex-wrap items-center gap-2">
                                        <span>{{ ev.time }}</span>
                                        <span>• {{ ev.kindLabel }}</span>
                                        <span>• Пост: {{ ev.place }}</span>
                                        <span>• {{ ev.assigned }} / {{ ev.capacity }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3 text-xs text-slate-600 flex flex-wrap gap-4">
                    <div class="inline-flex items-center gap-2">
                        <span class="w-3 h-3 rounded" style="background:#80D2F9"></span>
                        <span>Мои смены</span>
                    </div>
                    <div>🔁 Кнопка «Подмена» — отправить заявку на подмену этой смены</div>
                </div>
            </section>

            <!-- SIDEBAR -->
            <aside class="py-4 space-y-4 md:col-span-1">
                <!-- Входящие заявки (desktop) -->
                <div class="bg-white border rounded-2xl shadow-sm">
                    <div class="px-4 py-3 border-b flex items-center justify-between">
                        <h3 class="font-semibold text-sm">Запросы коллег</h3>
                        <div class="text-[11px] flex gap-1">
                            <button
                                @click="requestsScope='day'"
                                :class="requestsScope==='day'
                                    ? 'bg-slate-900 text-white'
                                    : 'bg-slate-100 text-slate-700'"
                                class="rounded-full px-2 py-1"
                            >
                                за день
                            </button>
                            <button
                                @click="requestsScope='week'"
                                :class="requestsScope==='week'
                                    ? 'bg-slate-900 text-white'
                                    : 'bg-slate-100 text-slate-700'"
                                class="rounded-full px-2 py-1"
                            >
                                за неделю
                            </button>
                        </div>
                    </div>
                    <div class="max-h-[360px] overflow-auto divide-y">
                        <div
                            v-for="rq in visibleRequestsDesktop"
                            :key="rq.id"
                            class="p-3 flex items-start gap-3"
                        >
                            <div
                                class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center text-xs font-semibold text-slate-700"
                            >
                                {{ rq.name.slice(0,1) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium flex items-center gap-2">
                                    <span class="truncate">
                                        {{ rq.name }}
                                    </span>
                                    <span
                                        class="text-[10px] px-1.5 py-0.5 rounded-full border"
                                        :class="rq.type==='all'
                                            ? 'bg-slate-50 text-slate-700 border-slate-200'
                                            : 'bg-indigo-50 text-indigo-700 border-indigo-200'"
                                    >
                                        {{ rq.type==='all' ? 'общая' : 'личная' }}
                                    </span>
                                </div>
                                <div class="text-[11px] text-slate-500 truncate mt-0.5">
                                    {{ rq.dateLabel }} • {{ rq.kindLabel }} • {{ rq.time }}
                                </div>
                                <div class="text-xs mt-1" v-if="rq.note">
                                    {{ rq.note }}
                                </div>
                                <div class="mt-2 flex gap-2">
                                    <button
                                        @click="acceptRequest(rq)"
                                        :disabled="rq.busy || rq.status!=='pending'"
                                        class="inline-flex items-center gap-2 rounded-full bg-emerald-600 text-white px-3 py-1.5 text-[11px] font-medium disabled:opacity-50"
                                    >
                                        <span v-if="rq.busy" class="spinner"></span>
                                        <span>Подменить</span>
                                    </button>
                                    <button
                                        @click="declineRequest(rq)"
                                        :disabled="rq.busy || rq.status!=='pending'"
                                        class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-[11px] text-slate-700 disabled:opacity-50"
                                    >
                                        <span v-if="rq.busy" class="spinner spinner-dark"></span>
                                        <span>Отказаться</span>
                                    </button>
                                </div>
                                <div
                                    v-if="rq.status!=='pending'"
                                    class="mt-1 text-[11px]"
                                    :class="rq.status==='accepted'
                                        ? 'text-emerald-700'
                                        : 'text-slate-500'"
                                >
                                    {{ rq.status==='accepted'
                                    ? 'Вы откликнулись. Ждёт подтверждения.'
                                    : 'Вы отказались.' }}
                                </div>
                            </div>
                        </div>
                        <div v-if="!visibleRequestsDesktop.length" class="p-4 text-sm text-slate-500">
                            Заявок нет.
                        </div>
                    </div>
                </div>

                <!-- Быстрая подмена -->
                <div class="bg-white border rounded-2xl p-4 shadow-sm">
                    <h3 class="font-semibold mb-2 text-sm">Быстрая подмена</h3>

                    <div class="text-xs text-slate-600 border rounded-xl bg-slate-50 px-3 py-2 mb-3 leading-snug">
                        Создайте <b>общую</b> заявку (видят все) или <b>личную</b>
                        (только выбранная медсестра и старшая).
                    </div>

                    <div class="space-y-3 text-sm">
                        <!-- Дата -->
                        <div>
                            <label class="text-slate-700 text-xs">Дата</label>
                            <input
                                type="date"
                                v-model="quick.date"
                                :min="minDate"
                                class="mt-1 w-full rounded-xl border px-3 py-2 text-sm"
                            >
                        </div>

                        <!-- Смена -->
                        <div>
                            <label class="text-slate-700 text-xs">Смена</label>
                            <select
                                v-model="quick.shift"
                                class="mt-1 w-full rounded-xl border px-3 py-2 text-sm"
                            >
                                <option value="12h">12 часов</option>
                                <option value="24h">24 часа</option>
                            </select>
                        </div>

                        <!-- Тип заявки: сегментный переключатель -->
                        <div>
                            <label class="text-slate-700 text-xs">Тип заявки</label>
                            <div class="mt-1 inline-flex w-full rounded-xl bg-slate-100 p-1">
                                <button
                                    type="button"
                                    @click="quick.targetType='all'"
                                    class="w-1/2 rounded-lg px-3 py-1.5 text-xs font-medium transition"
                                    :class="quick.targetType==='all'
                                        ? 'bg-white shadow-sm text-slate-900'
                                        : 'text-slate-600'"
                                >
                                    Общая
                                </button>
                                <button
                                    type="button"
                                    @click="quick.targetType='direct'"
                                    class="w-1/2 rounded-lg px-3 py-1.5 text-xs font-medium transition"
                                    :class="quick.targetType==='direct'
                                        ? 'bg-white shadow-sm text-slate-900'
                                        : 'text-slate-600'"
                                >
                                    Личная
                                </button>
                            </div>
                            <div class="mt-1 text-[11px] text-slate-500">
                                {{ quick.targetType === 'all'
                                ? 'Заявку увидят все медсестры отделения.'
                                : 'Заявка придёт выбранной медсестре и старшей.' }}
                            </div>
                        </div>

                        <!-- выбор адресата для личной -->
                        <div v-if="quick.targetType==='direct'">
                            <label class="text-slate-700 text-xs">Медсестра</label>
                            <div class="relative mt-1">
                                <input
                                    v-model="quick.search"
                                    @input="filterColleagues('quick')"
                                    placeholder="Начните вводить имя"
                                    class="w-full rounded-xl border px-3 py-2 text-sm"
                                >
                                <div
                                    v-if="directSearch.length"
                                    class="absolute z-10 mt-1 w-full bg-white border rounded-xl max-h-48 overflow-auto shadow-soft"
                                >
                                    <button
                                        v-for="c in directSearch"
                                        :key="c.id"
                                        @click="selectDirect(c)"
                                        class="w-full text-left px-3 py-2 hover:bg-slate-50 flex items-center gap-2 text-xs"
                                    >
                                        <span class="w-5 h-5 rounded-full bg-slate-200 inline-flex items-center justify-center text-[10px] font-semibold">
                                            {{ c.name.slice(0,1) }}
                                        </span>
                                        <span>{{ c.name }} • {{ c.department }}</span>
                                    </button>
                                </div>
                            </div>
                            <div
                                v-if="quick.targetUser"
                                class="mt-1 text-[11px] text-slate-600"
                            >
                                Адресат: <b>{{ quick.targetUser.name }}</b>
                            </div>
                        </div>

                        <!-- Комментарий -->
                        <div>
                            <label class="text-slate-700 text-xs">Комментарий</label>
                            <textarea
                                v-model="quick.note"
                                rows="2"
                                class="mt-1 w-full rounded-xl border px-3 py-2 text-sm"
                                placeholder="Например: нужно уйти раньше, поменяться на утро и т.п."
                            ></textarea>
                        </div>

                        <!-- основная кнопка -->
                        <button
                            @click="submitQuick"
                            class="w-full rounded-xl bg-brand-600 text-white py-2.5 text-sm font-semibold flex justify-center items-center gap-2 shadow-soft hover:bg-indigo-700 transition disabled:opacity-50"
                        >
                            <span v-if="quickBusy" class="spinner"></span>
                            <span>Отправить заявку на подмену</span>
                        </button>
                    </div>
                </div>

                <!-- Мои заявки -->
                <div class="bg-white border rounded-2xl shadow-sm">
                    <div class="px-4 py-3 border-b flex items-center justify-between">
                        <h3 class="font-semibold text-sm">Мои заявки</h3>
                        <button
                            @click="clearAllRequests"
                            class="text-[11px] text-slate-500 hover:text-red-600"
                        >
                            очистить (локально)
                        </button>
                    </div>
                    <div class="max-h-[360px] overflow-auto divide-y">
                        <div v-if="!requestsSorted.length" class="p-4 text-sm text-slate-500">
                            Пока нет заявок.
                        </div>
                        <div
                            v-for="r in requestsSorted"
                            :key="r.id"
                            class="p-3 flex items-start gap-3 text-xs"
                        >
                            <div class="mt-1">
                                <div
                                    :class="badgeClass(r.status)"
                                    class="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase tracking-wide"
                                >
                                    {{ statusText(r.status) }}
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium flex items-center gap-2">
                                    <span>
                                        {{ fmt(r.date) }} — {{ shiftLabel(r.shift) }}
                                    </span>
                                    <span
                                        class="text-[10px] px-1.5 py-0.5 rounded-full border"
                                        :class="r.targetType==='all'
                                            ? 'bg-slate-50 text-slate-700 border-slate-200'
                                            : 'bg-indigo-50 text-indigo-700 border-indigo-200'"
                                    >
                                        {{ r.targetType==='all' ? 'общая' : 'личная' }}
                                    </span>
                                    <span
                                        v-if="r.targetType==='direct' && r.targetUser"
                                        class="text-[11px] text-slate-500 truncate"
                                    >
                                        → {{ r.targetUser.name }}
                                    </span>
                                </div>
                                <div
                                    class="text-[11px] text-slate-500 mt-0.5"
                                    v-if="r.note"
                                >
                                    {{ r.note }}
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2 text-[11px]">
                                    <span
                                        v-if="r.offers?.length"
                                        class="inline-flex items-center gap-1 rounded-full bg-emerald-50 text-emerald-700 px-2 py-1 border border-emerald-200"
                                    >
                                        Откликов: {{ r.offers.length }}
                                    </span>
                                    <button
                                        v-if="r.status==='await_colleagues'"
                                        @click="demoAddOffer(r)"
                                        class="rounded-full border px-2 py-1 hover:bg-slate-50"
                                    >
                                        Демо: отклик
                                    </button>
                                    <button
                                        v-if="r.status==='await_colleagues' && r.offers?.length"
                                        @click="sendToHead(r)"
                                        class="rounded-full bg-amber-50 text-amber-700 px-2 py-1 border border-amber-200"
                                    >
                                        Отправить старшей
                                    </button>
                                    <button
                                        v-if="r.status==='await_head'"
                                        @click="approveByHead(r)"
                                        class="rounded-full bg-emerald-600 text-white px-2 py-1"
                                    >
                                        Демо: утвердить
                                    </button>
                                    <button
                                        v-if="r.status==='approved'"
                                        @click="revertRequest(r)"
                                        class="rounded-full bg-slate-100 px-2 py-1"
                                    >
                                        Отменить
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Статистика -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-white border rounded-2xl p-4 shadow-sm">
                        <div class="text-xs text-slate-500">Баланс подмен</div>
                        <div class="text-2xl font-semibold mt-1">
                            {{ stats.given }} / {{ stats.taken }}
                        </div>
                        <div class="text-[11px] text-slate-500 mt-1">
                            Отдал(а) / Взял(а)
                        </div>
                    </div>
                    <div class="bg-white border rounded-2xl p-4 shadow-sm">
                        <div class="text-xs text-slate-500">Ближайшая смена</div>
                        <div class="font-medium mt-1 text-sm">
                            {{ fmt(nextShift.date) }} — {{ shiftLabel(nextShift.shift) }}
                        </div>
                        <div class="text-[11px] text-slate-500 mt-1">
                            Отделение: {{ nextShift.department }}
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </main>

    <!-- Модалка "Мне нужна подмена" -->
    <div v-if="swap.open" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-slate-900/50" @click="swap.open=false"></div>
        <div class="absolute inset-0 flex items-end md:items-center justify-center p-3">
            <div class="w-full max-w-md bg-white rounded-2xl p-4 shadow-soft">
                <div class="font-semibold text-slate-900 text-base">
                    Заявка на подмену
                </div>
                <div
                    class="text-sm text-slate-600 mt-1"
                    v-if="swap.ev"
                >
                    {{ swap.ev.title }} — {{ swap.ev.time }}
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button
                        @click="sendSwap('all')"
                        class="rounded-full px-3 py-2 bg-slate-900 text-white flex justify-center gap-2 text-sm font-medium disabled:opacity-50"
                    >
                        <span v-if="swap.busy" class="spinner"></span>
                        <span>Общая</span>
                    </button>
                    <button
                        @click="swap.direct=true"
                        class="rounded-full px-3 py-2 border text-sm"
                    >
                        Личная
                    </button>
                </div>
                <div v-if="swap.direct" class="mt-3">
                    <input
                        v-model="swap.search"
                        @input="filterColleagues('modal')"
                        class="w-full border rounded-xl px-3 py-2 text-sm"
                        placeholder="Выберите медсестру"
                    />
                    <div
                        v-if="modalSearch.length"
                        class="mt-1 border rounded-xl max-h-40 overflow-auto"
                    >
                        <button
                            v-for="c in modalSearch"
                            :key="c.id"
                            class="w-full text-left px-3 py-2 hover:bg-slate-50 text-sm"
                            @click="swap.target=c; modalSearch=[]"
                        >
                            {{ c.name }} • {{ c.department }}
                        </button>
                    </div>
                    <div
                        v-if="swap.target"
                        class="text-xs text-slate-600 mt-1"
                    >
                        Адресат: <b>{{ swap.target.name }}</b>
                    </div>
                    <button
                        @click="sendSwap('direct')"
                        class="mt-2 rounded-full px-3 py-2 bg-brand-600 text-white w-full flex justify-center gap-2 text-sm font-medium disabled:opacity-50"
                    >
                        <span v-if="swap.busy" class="spinner"></span>
                        <span>Отправить личную</span>
                    </button>
                </div>
                <button
                    class="mt-3 w-full rounded-full px-3 py-2 border text-sm"
                    @click="swap.open=false"
                >
                    Отмена
                </button>
            </div>
        </div>
    </div>

    <!-- Toasts -->
    <div class="fixed bottom-4 right-4 space-y-2 z-[60]">
        <div
            v-for="t in toasts"
            :key="t.id"
            class="bg-slate-900 text-white/95 rounded-xl px-4 py-3 shadow-soft text-sm"
        >
            {{ t.text }}
        </div>
    </div>
</template>

<style>
.no-scrollbar::-webkit-scrollbar {
    display: none;
}

/* линии сетки */
.grid-hour {
    position: relative;
}
.grid-hour::after {
    content:"";
    position:absolute;
    inset-inline:0;
    bottom:0;
    height:1px;
    background:rgba(15,23,42,.06);
}

/* карточки смен */
.event-card {
    box-shadow: 0 12px 24px -18px rgba(2,6,23,.28);
}

/* красная линия "сейчас" */
.now-line {
    position:absolute;
    left:0;
    right:0;
    height:2px;
    background:#ef4444;
}

/* спиннеры */
.spinner {
    width:14px;
    height:14px;
    border-radius:50%;
    border:2px solid rgba(255,255,255,.6);
    border-top-color:#fff;
    animation:spin .8s linear infinite;
}
.spinner-dark {
    border-color:rgba(15,23,42,.4);
    border-top-color:#0f172a;
}
@keyframes spin {
    to { transform:rotate(360deg); }
}

/* мягкая тень */
.shadow-soft {
    box-shadow: 0 18px 45px rgba(15,23,42,0.16);
}
</style>
