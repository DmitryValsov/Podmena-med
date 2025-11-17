<script setup>
;
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';



const page = usePage();
const user = page.props.auth.user;

const pad=n=>String(n).padStart(2,'0');
const toISO=d=>{const x=new Date(d); x.setHours(0,0,0,0); return `${x.getFullYear()}-${pad(x.getMonth()+1)}-${pad(x.getDate())}`}
const fromISO=s=>{const [y,m,d]=s.split('-').map(Number); return new Date(y,m-1,d)}
const addDays=(d,n)=>{const x=new Date(d); x.setDate(x.getDate()+n); return x}
const hhmmToMinutes=s=>{const [h,m]=s.split(':').map(Number); return h*60+(m||0)}
function cryptoId(){ return Math.random().toString(36).slice(2) }

const PLACES=['пост 1','пост 2','процедурная','перевязочная'];
const DEPTS=['Хирургия','Терапия','Кардиология','Приёмное'];

function seedStaff(){
    const names=['Анна Петрова','Екатерина Ильина','Мария Сидорова','Динара Кадырова','Ольга Лебедева','Ксения Орлова','Ирина Павлова','Вера Баранова'];
    return names.map((n,i)=>({ id:'n'+i, name:n, department:DEPTS[i%DEPTS.length], avatar:`https://i.pravatar.cc/100?img=${30+i}` }));
}
function newSlot(iso,kind,part,start,end,place,capacity){
    let endLabel=end; if(kind==='24h') endLabel='08:00+1'; if(part==='night' && end==='08:00') endLabel='08:00+1';
    return { id:cryptoId(), dateISO:iso, kind, part, start, end:endLabel, place, capacity, nurses:[], color:['apple.pink','apple.yellow','apple.mint','apple.blue','apple.teal','apple.orange','apple.coral'][Math.floor(Math.random()*7)] };
}
function seedWeek(weekStartISO){
    const ws=fromISO(weekStartISO); const out=[]; for(let i=0;i<7;i++){ const iso=toISO(addDays(ws,i)); out.push(newSlot(iso,'12h','day','08:00','20:00','пост 1',2)); out.push(newSlot(iso,'12h','night','20:00','08:00','пост 1',2)); if(i%2===0) out.push(newSlot(iso,'24h',null,'08:00','08:00','процедурная',2)); } return out;
}
function seedSwaps(staff, ws){ const reqs=[]; const pick=()=>staff[Math.floor(Math.random()*staff.length)]; for(let i=0;i<3;i++){ const requester=pick(); reqs.push({ id:'rq'+i, requester, dateISO: toISO(addDays(ws,i)), partLabel: i%2? '12ч ночь':'12ч день', status:'pending', busy:false }); } return reqs; }

        const today = new Date();
        const weekStart = ref(getWeekStart(today));
        const gridStart = ref('08:00');
        const gridEnd = ref('21:00');
        const pxPerHour = ref(80);
        const staff = ref(seedStaff());
        const slots = ref(seedWeek(toISO(weekStart.value)));
        const swaps = ref(seedSwaps(staff.value, weekStart.value));
        const filters = ref({department:'', search:''});
        const ui = ref({ dragging:null, slotEditor:null });
        const toasts = ref([]);

        const departments = DEPTS; const places = PLACES;
        const monthTitle = computed(()=>{ const d=weekStart.value; const months=['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь']; return `${months[d.getMonth()]} ${d.getFullYear()}`;});
        const weekNumber = computed(()=>getWeekNumber(weekStart.value));
        const weekDays = computed(()=> Array.from({length:7}).map((_,i)=>{ const d=addDays(weekStart.value,i); const wd=['Пн','Вт','Ср','Чт','Пт','Сб','Вс'][(d.getDay()+6)%7]; return {iso:toISO(d), date:d, label:`${pad(d.getDate())}.${pad(d.getMonth()+1)}`, weekday:wd} }));

        const ticks = computed(()=>{ const s=hhmmToMinutes(gridStart.value), e=hhmmToMinutes(gridEnd.value), out=[]; for(let m=s; m<=e; m+=60) out.push(`${pad(Math.floor(m/60))}:00`); return out; });
        const nowInGrid = computed(()=>{ const n=new Date(), m=n.getHours()*60+n.getMinutes(); const s=hhmmToMinutes(gridStart.value), e=hhmmToMinutes(gridEnd.value); return m>=s && m<=e; });
        const nowLineStyle = computed(()=>{ const n=new Date(); const minutes=n.getHours()*60+n.getMinutes()-hhmmToMinutes(gridStart.value); const top = minutes/60*pxPerHour.value + 32; return { top: top+'px' } });

        const filteredStaff = computed(()=>{ let list=[...staff.value]; if(filters.value.department) list=list.filter(n=>n.department===filters.value.department); if(filters.value.search) list=list.filter(n=>n.name.toLowerCase().includes(filters.value.search.toLowerCase())); return list; });

        function isTodayISO(iso){ return toISO(new Date())===iso }
        function minutesFromGridStart(hm){ return hhmmToMinutes(hm)-hhmmToMinutes(gridStart.value) }
        function color(token, a){ const map={'apple.pink':'#FEA8BF','apple.yellow':'#FEC30B','apple.mint':'#90E696','apple.blue':'#80D2F9','apple.teal':'#7DE5E6','apple.orange':'#FDB206','apple.coral':'#FF5780'}; const hex=map[token]||'#a5b4fc'; if(a==null) return hex; const r=parseInt(hex.slice(1,3),16),g=parseInt(hex.slice(3,5),16),b=parseInt(hex.slice(5,7),16); return `rgba(${r},${g},${b},${a})`; }
        function slotStyle(s){ const startMin=minutesFromGridStart(s.start); const endLabel=s.end.includes('+1')?s.end.replace('+1',''):s.end; const endAbs=hhmmToMinutes(endLabel)+(s.end.includes('+1')?24*60:0); const endMin=endAbs-hhmmToMinutes(gridStart.value); const top=startMin/60*pxPerHour.value+32; const height=(endMin-startMin)/60*pxPerHour.value-4; return { top: top+'px', height: Math.max(28,height)+'px', backgroundColor: color(s.color,.25), borderColor: color(s.color,.9)} }
        function timeLabel(s){ return `${s.start}–${s.end.replace('+1',' (+1)')}` }
        function daySlots(iso){ return slots.value.filter(s=>s.dateISO===iso).sort((a,b)=>hhmmToMinutes(a.start)-hhmmToMinutes(b.start)) }

        function dragStartStaff(n){ ui.value.dragging={type:'staff', data:n}; }
        function dropOnSlot(slot){ if(!ui.value.dragging) return; const p=ui.value.dragging; if(p.type==='staff'){ const n=p.data; if(!slot.nurses.find(x=>x.id===n.id)){ slot.nurses.push(n); toast(`Назначили ${n.name} → ${slot.place}`) } } ui.value.dragging=null; }
        function dropOnDay(iso){ if(!ui.value.dragging) return; const p=ui.value.dragging; if(p.type==='staff'){ const n=p.data; const slot=newSlot(iso,'12h','day','08:00','20:00','пост 1',2); slot.nurses.push(n); slots.value.push(slot); toast(`Создан слот и назначен ${n.name}`) } ui.value.dragging=null; }
        function removeFromSlot(slot,n){ slot.nurses=slot.nurses.filter(x=>x.id!==n.id) }

        function editSlot(s){ ui.value.slotEditor=JSON.parse(JSON.stringify(s)) }
        function saveSlot(){ const e=ui.value.slotEditor; const idx=slots.value.findIndex(x=>x.id===e.id); if(idx>-1) slots.value[idx]=e; ui.value.slotEditor=null; toast('Слот сохранён') }
        function deleteSlot(s){ slots.value=slots.value.filter(x=>x.id!==s.id); ui.value.slotEditor=null; toast('Слот удалён') }
        function createEmptySlot(){ const iso=toISO(weekStart.value); slots.value.push(newSlot(iso,'12h','day','09:00','15:00','пост 2',1)) }

        function runAnalysis(){ let problems=0; for(const s of slots.value){ if(s.nurses.length<s.capacity) problems++; } toast(problems?`Есть недокомплектов: ${problems}`:'Проблем нет ✅') }
        function loadWeekTemplate(){ const iso=toISO(weekStart.value); slots.value=slots.value.filter(s=> s.dateISO<iso || s.dateISO>toISO(addDays(weekStart.value,6))); slots.value.push(...seedWeek(iso)); toast('Шаблон загружен') }
        function expandToMonth(){ const start=new Date(weekStart.value.getFullYear(),weekStart.value.getMonth(),1); const ws=getWeekStart(start); const m=start.getMonth(); slots.value=slots.value.filter(s=> fromISO(s.dateISO).getMonth()!==m); for(let w=0; w<5; w++){ const iso=toISO(addDays(ws,w*7)); slots.value.push(...seedWeek(iso)); } toast('Растянуто на месяц') }
        const canPublish = computed(()=> slots.value.length>0 && !pendingSwaps.value.length )
        function publish(){ toast('График опубликован') }

        const pendingSwaps = computed(()=> swaps.value.filter(x=>x.status==='pending'))
        function approveSwap(rq){ rq.busy=true; setTimeout(()=>{ rq.status='approved'; rq.busy=false; toast('Подмена утверждена') },350) }
        function declineSwap(rq){ rq.busy=true; setTimeout(()=>{ rq.status='declined'; rq.busy=false; toast('Заявка отклонена') },250) }

        function resetFilters(){ filters.value={department:'', search:''} }
        function toast(text){ const id=cryptoId(); toasts.value.push({id,text}); setTimeout(()=>{ toasts.value = toasts.value.filter(t=>t.id!==id) }, 2300) }
        function fmtDate(iso){ const d=fromISO(iso); const months=['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря']; const wd=['Пн','Вт','Ср','Чт','Пт','Сб','Вс'][(d.getDay()+6)%7]; return `${pad(d.getDate())} ${months[d.getMonth()]} (${wd})` }
function getWeekStart(date){ const d=new Date(date); const day=(d.getDay()+6)%7; d.setDate(d.getDate()-day); d.setHours(0,0,0,0); return d }
function getWeekNumber(date){ const d=new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate())); const dayNum = d.getUTCDay() || 7; d.setUTCDate(d.getUTCDate() + 4 - dayNum); const yearStart = new Date(Date.UTC(d.getUTCFullYear(),0,1)); return Math.ceil((((d - yearStart) / 86400000) + 1)/7) }

</script>

<template>
    <Head title="Welcome">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>



    <h1 v-if="user.name == 'Команич Роман Маркович'">Admin!</h1>
    <h1 v-else>No admin 😢</h1>




    <div id="app" class="min-h-screen flex flex-col">
        <header class="bg-gradient-to-r from-brand-600 to-blue-600 text-white">
            <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="size-11 rounded-2xl bg-white/15 grid place-items-center shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-width="2" d="M3 5h18M3 12h18M3 19h18"/></svg>
                    </div>
                    <div>
                        <div class="text-sm/5 opacity-90">Панель старшей м/с</div>
                        <div class="text-lg font-semibold">Виктория Методист</div>
                        <div class="text-xs/4 opacity-90">{{ monthTitle }} • неделя {{ weekNumber }}</div>
                    </div>
                </div>
                <div class="hidden md:flex items-center gap-2">
                    <button @click="loadWeekTemplate" class="rounded-xl bg-white text-brand-700 px-4 py-2 font-medium shadow hover:bg-brand-50">Загрузить шаблон</button>
                    <button @click="expandToMonth" class="rounded-xl bg-white/90 text-brand-700 px-4 py-2 font-medium shadow hover:bg-white">На месяц</button>
                    <button @click="runAnalysis" class="rounded-xl bg-amber-400/90 text-slate-900 px-4 py-2 font-medium shadow hover:bg-amber-300">Проверка</button>
                    <button :disabled="!canPublish" @click="publish" class="rounded-xl bg-emerald-600 disabled:opacity-50 text-white px-4 py-2 font-semibold shadow hover:bg-emerald-700">Опубликовать</button>
                </div>
            </div>
        </header>

        <div class="max-w-7xl mx-auto w-full px-4 py-4 grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Левая колонка -->
            <aside class="lg:col-span-3 space-y-6">
                <div class="bg-white/90 glass border border-slate-200 rounded-2xl shadow-soft p-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold">Фильтры</h3>
                        <button @click="resetFilters" class="text-xs text-slate-500 hover:text-slate-700">сброс</button>
                    </div>
                    <div class="mt-3 space-y-3">
                        <div>
                            <label class="text-sm text-slate-600">Отделение</label>
                            <select v-model="filters.department" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                <option value="">Все</option>
                                <option v-for="d in departments" :key="d" :value="d">{{ d }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm text-slate-600">Поиск</label>
                            <input v-model="filters.search" type="text" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Имя"/>
                        </div>
                    </div>
                </div>

                <div class="bg-white/90 glass border border-slate-200 rounded-2xl shadow-soft p-4">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold">Сотрудники</h3>
                        <div class="text-xs text-slate-500">{{ filteredStaff.length }}</div>
                    </div>
                    <div class="grid grid-cols-1 gap-2 max-h-[380px] overflow-auto pr-1">
                        <div v-for="n in filteredStaff" :key="n.id" class="flex items-center gap-2 p-2 rounded-lg border hover:bg-slate-50 cursor-grab active:cursor-grabbing" draggable="true" @dragstart="dragStartStaff(n)">
                            <img :src="n.avatar" class="size-8 rounded-full"/>
                            <div class="flex-1">
                                <div class="text-sm font-medium">{{ n.name }}</div>
                                <div class="text-xs text-slate-500">{{ n.department }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white/90 glass border border-slate-200 rounded-2xl shadow-soft">
                    <div class="p-4 border-b border-slate-200 flex items-center justify-between">
                        <h3 class="font-semibold">Заявки на подмену</h3>
                        <span class="text-xs text-slate-500">ожидают: {{ pendingSwaps.length }}</span>
                    </div>
                    <div class="p-2 divide-y divide-slate-100 max-h-[320px] overflow-auto">
                        <div v-if="!pendingSwaps.length" class="p-3 text-sm text-slate-500">Пока нет заявок.</div>
                        <div v-for="rq in pendingSwaps" :key="rq.id" class="p-3 flex items-start gap-3">
                            <img :src="rq.requester.avatar" class="size-9 rounded-full"/>
                            <div class="flex-1">
                                <div class="text-sm font-medium">{{ rq.requester.name }}</div>
                                <div class="text-xs text-slate-500">{{ fmtDate(rq.dateISO) }} • {{ rq.partLabel }}</div>
                                <div class="mt-2 flex gap-2">
                                    <button :disabled="rq.busy" @click="approveSwap(rq)" class="rounded bg-emerald-600 disabled:opacity-50 text-white px-3 py-1.5">Утвердить</button>
                                    <button :disabled="rq.busy" @click="declineSwap(rq)" class="rounded border px-3 py-1.5 hover:bg-slate-50">Отклонить</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- Сетка расписания -->
            <section class="lg:col-span-9 space-y-4">
                <div class="bg-white/90 glass border border-slate-200 rounded-2xl shadow-soft p-3 sm:p-4">
                    <div class="flex flex-wrap gap-2 items-center">
                        <button @click="shiftWeek(-1)" class="rounded-lg border px-2 py-1.5 hover:bg-slate-50">◀</button>
                        <div class="text-lg font-semibold mr-2">{{ monthTitle }}</div>
                        <button @click="shiftWeek(1)" class="rounded-lg border px-2 py-1.5 hover:bg-slate-50">▶</button>
                        <div class="flex-1"></div>
                        <label class="text-sm text-slate-600">Начало<input type="time" v-model="gridStart" class="ml-2 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"></label>
                        <label class="text-sm text-slate-600">Конец<input type="time" v-model="gridEnd" class="ml-2 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"></label>
                        <label class="text-sm text-slate-600">Масштаб
                            <select v-model.number="pxPerHour" class="ml-2 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                                <option :value="60">60px/ч</option>
                                <option :value="80">80px/ч</option>
                                <option :value="100">100px/ч</option>
                            </select>
                        </label>
                        <button @click="createEmptySlot" class="ml-2 rounded-lg border px-3 py-1.5 text-sm hover:bg-slate-50">+ слот</button>
                    </div>
                </div>

                <div class="bg-white/90 glass border border-slate-200 rounded-2xl shadow-soft overflow-x-auto">
                    <div class="min-w-[900px]">
                        <div class="grid" :style="{gridTemplateColumns: '120px repeat(7, 1fr)'}">
                            <div class="border-b border-slate-200 p-2 text-xs text-slate-500">Время</div>
                            <div v-for="d in weekDays" :key="d.iso" class="border-b border-slate-200 p-2 text-center">
                                <div class="text-xs text-slate-500">{{ d.weekday }}</div>
                                <div class="font-medium" :class="isTodayISO(d.iso)?'text-brand-700':''">{{ d.label }}</div>
                            </div>
                        </div>
                        <div class="relative">
                            <div v-if="nowInGrid" class="now-line" :style="nowLineStyle"></div>
                            <div class="grid" :style="{gridTemplateColumns: '120px repeat(7, 1fr)'}">
                                <div class="relative border-r border-slate-200">
                                    <div v-for="tick in ticks" :key="tick" class="text-xs text-slate-500" :style="{height: pxPerHour+'px', borderBottom: '1px solid #e5e7eb', paddingTop:'2px'}">{{ tick }}</div>
                                </div>
                                <div v-for="day in weekDays" :key="day.iso" class="relative border-r border-slate-100">
                                    <div v-for="tick in ticks" :key="day.iso + tick" :style="{height: pxPerHour+'px', borderBottom:'1px solid #f1f5f9'}"></div>
                                    <div class="absolute inset-0" @dragover.prevent @drop="dropOnDay(day.iso, $event)"></div>
                                    <div v-for="s in daySlots(day.iso)" :key="s.id" class="absolute left-1 right-1 rounded-xl border shadow-sm overflow-hidden cursor-pointer bg-white/70 hover:ring-1 hover:ring-brand-400" :style="slotStyle(s)" @dragover.prevent @drop="dropOnSlot(s, $event)" @click.stop="editSlot(s)">
                                        <div class="px-2 py-1 text-[11px] flex items-center justify-between bg-white/70">
                                            <div class="font-medium truncate">{{ s.place }} • {{ s.kind==='24h'?'24 часа': s.part==='night'?'12ч ночь':'12ч день' }}</div>
                                            <div class="text-slate-500">{{ timeLabel(s) }}</div>
                                        </div>
                                        <div class="px-2 py-1 text-[11px] space-y-0.5 bg-white/60">
                                            <div class="flex items-center gap-1 flex-wrap">
                      <span v-for="n in s.nurses" :key="n.id" class="inline-flex items-center gap-1 rounded bg-slate-50 border border-slate-200 px-1.5 py-0.5">
                        <img :src="n.avatar" class="size-4 rounded-full"/> {{ n.name }}
                        <button class="ml-1 text-slate-500 hover:text-red-600" @click.stop="removeFromSlot(s, n)">✕</button>
                      </span>
                                                <span v-if="!s.nurses.length" class="text-slate-400">перетащите сотрудника…</span>
                                            </div>
                                            <div class="text-[10px] text-slate-500">Нужно: {{ s.capacity }} • Назначено: {{ s.nurses.length }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div v-if="ui.slotEditor" class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-slate-900/50" @click="ui.slotEditor=null"></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
                <div class="w-full max-w-lg bg-white rounded-2xl border border-slate-200 shadow-soft overflow-hidden">
                    <div class="p-3 border-b border-slate-200 flex items-center justify-between">
                        <h3 class="font-semibold">Редактировать слот</h3>
                        <button @click="ui.slotEditor=null" class="text-slate-500 hover:text-slate-800">✕</button>
                    </div>
                    <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm text-slate-600">Дата</label>
                            <input type="date" v-model="ui.slotEditor.dateISO" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label class="text-sm text-slate-600">Пост</label>
                            <select v-model="ui.slotEditor.place" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                <option v-for="p in places" :key="p" :value="p">{{ p }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm text-slate-600">Начало</label>
                            <input type="time" v-model="ui.slotEditor.start" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label class="text-sm text-slate-600">Конец</label>
                            <input type="time" v-model="ui.slotEditor.end" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label class="text-sm text-slate-600">Тип</label>
                            <select v-model="ui.slotEditor.kind" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                <option value="12h">12 часов</option>
                                <option value="24h">24 часа</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm text-slate-600">Требуется</label>
                            <input type="number" min="1" v-model.number="ui.slotEditor.capacity" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                    </div>
                    <div class="p-4 border-t border-slate-200 flex items-center justify-between">
                        <button @click="deleteSlot(ui.slotEditor)" class="text-red-600 hover:underline">Удалить</button>
                        <div class="flex items-center gap-2">
                            <button @click="ui.slotEditor=null" class="rounded-lg border px-3 py-1.5">Отмена</button>
                            <button @click="saveSlot" class="rounded-lg bg-brand-600 text-white px-4 py-1.5">Сохранить</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="fixed bottom-4 right-4 space-y-2 z-[60]">
            <div v-for="t in toasts" :key="t.id" class="bg-slate-900 text-white/95 rounded-xl px-4 py-3 shadow-soft">{{ t.text }}</div>
        </div>
    </div>
</template>
