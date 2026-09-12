'use strict';
const $ = s => document.querySelector(s);
const escapeHTML = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const safeURL = s => { try { const u = new URL(s); return ['https:','http:'].includes(u.protocol) ? u.href : ''; } catch { return ''; } };
const initials = s => s.replace(/\([^)]*\)/g,'').trim().split(/\s+/).filter(Boolean).map(x=>x[0]).slice(0,2).join('');
let data, filter='all', person='', limit=12, generation=0, read=new Set(), memoryAvailable=true;
let courseID = new URLSearchParams(location.search).get('course') || '';
function loadRead(){ try { const stored=JSON.parse(localStorage.getItem('course-voices:read:v1')||'[]'); read=new Set(Array.isArray(stored)?stored:[]); } catch { memoryAvailable=false; read=new Set(); } }
loadRead();
function remember(id, isRead) { isRead ? read.add(id) : read.delete(id); try { localStorage.setItem('course-voices:read:v1',JSON.stringify([...read])); } catch { memoryAvailable=false; $('#notice').hidden=false; $('#notice').textContent='This browser cannot save read status. Your place will be remembered only while this page stays open.'; } }
function notice(text){ $('#notice').hidden=!text; $('#notice').textContent=text; }
function render(){
 if(!data) return;
 const q=$('#search').value.trim().toLocaleLowerCase();
 let posts=data.posts.filter(p=>(!person||p.contributor===person) && (filter==='all'||(filter==='read')===read.has(p.id)) && (!q||`${p.title} ${p.author} ${p.excerpt}`.toLocaleLowerCase().includes(q)));
 posts.sort((a,b)=>((b.date||'').localeCompare(a.date||'')||a.id.localeCompare(b.id))*($('#sort').value==='oldest'?-1:1));
 $('#unread-count').textContent=data.posts.filter(p=>!read.has(p.id)).length;
 $('#post-count').textContent=data.posts.length;
 $('#results').textContent=`${posts.length} ${posts.length===1?'post':'posts'}${person?' by '+data.course.contributors.find(c=>c.id===person)?.name:''}${q?' matching your search':''}`;
 $('#posts').innerHTML=posts.length ? posts.slice(0,limit).map((p)=>{
  const r=read.has(p.id), url=escapeHTML(safeURL(p.url));
  return `<article class="post-card${r?' is-read':''}" data-id="${escapeHTML(p.id)}"><div class="card-body"><div class="card-meta"><time>${p.date?escapeHTML(new Date(p.date).toLocaleDateString('en-CA',{month:'short',day:'numeric',year:'numeric',timeZone:'UTC'})):'Date not supplied'}</time><span>${r?'✓ Read':'<i class="unread-dot"></i> Unread'}</span></div><h3><a href="${url}" data-open="${escapeHTML(p.id)}" target="_blank" rel="noopener noreferrer">${escapeHTML(p.title)}</a></h3><p class="excerpt">${escapeHTML(p.excerpt)}</p><div class="card-foot"><span class="author">By ${escapeHTML(p.author)}</span><button class="read-toggle" data-read="${escapeHTML(p.id)}" aria-label="Mark ${escapeHTML(p.title)} as ${r?'unread':'read'}">${r?'Mark unread':'Mark read'}</button></div></div></article>`;
 }).join('') : `<div class="empty"><h3>${data.posts.length?'A little quiet here.':'The conversation starts here.'}</h3><p>${data.posts.length?'Try another contributor, search, or reading filter.':'Posts will appear here as the class begins publishing.'}</p></div>`;
 $('#load-more').hidden=posts.length<=limit;
 document.querySelectorAll('[data-filter]').forEach(b=>{b.classList.toggle('active',b.dataset.filter===filter);b.setAttribute('aria-pressed',String(b.dataset.filter===filter));});
 document.querySelectorAll('[data-person]').forEach(b=>{b.classList.toggle('selected',b.dataset.person===person);b.setAttribute('aria-pressed',String(b.dataset.person===person));});
 $('#everyone').classList.toggle('selected',!person);$('#everyone').setAttribute('aria-pressed',String(!person));
}
function renderCourse(){
 document.title=data.course.title+' · '+data.course.term;
 $('#course-label').textContent=data.course.code+' / '+data.course.term;
 $('#site-title').textContent=data.course.title;
 if(data.course.title.startsWith('Voices of ')) $('#site-title').innerHTML='Voices of <em>'+escapeHTML(data.course.title.slice(10))+'</em>';
 $('#description').textContent=data.course.description;
 $('#semester').innerHTML=data.courses.map(c=>`<option value="${escapeHTML(c.id)}">${escapeHTML(c.code)} · ${escapeHTML(c.term)}</option>`).join('');$('#semester').value=data.course.id;
 $('#people-count').textContent=data.course.contributors.length;
 $('#contributors').innerHTML=data.course.contributors.map(c=>`<div class="person-row"><button class="person" data-person="${escapeHTML(c.id)}" aria-pressed="false"><span>${escapeHTML(c.name)}</span></button><a class="blog-link" href="${escapeHTML(safeURL(c.url))}" target="_blank" rel="noopener noreferrer" aria-label="Visit ${escapeHTML(c.name)}’s blog">↗</a></div>`).join('');
 $('#updated').textContent=data.checked?'Feeds checked '+new Date(data.checked).toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'})+'.':'Waiting for the first feed check.';
 if(!memoryAvailable) notice('This browser cannot save read status between visits.');
 else if(data.feedErrors) notice(`${data.feedErrors} ${data.feedErrors===1?'blog is':'blogs are'} temporarily unavailable. Previously collected posts are still here.`);
 else notice('');
 render();
}
async function load(quiet=false){
 const ticket=++generation, id=courseID;
 try{const res=await fetch('api.php?course='+encodeURIComponent(id));if(!res.ok)throw Error('Could not load this course.');const result=await res.json();if(ticket!==generation)return;data=result;courseID=data.course.id;renderCourse();
 if(data.stale&&!quiet){$('#updated').textContent='Checking blogs for new posts…';try{const refreshed=await fetch('api.php?action=refresh&course='+encodeURIComponent(courseID),{method:'POST',headers:{'X-Course-Voices':'1'}});if(!refreshed.ok)throw Error();if(ticket===generation)await load(true);}catch{if(ticket===generation)notice('The latest feed check could not finish. Showing previously collected posts.');}}
 }catch(e){if(ticket!==generation)return;notice(e.message+' Please try reloading.');if(!data)$('#posts').innerHTML='<div class="empty"><h3>We couldn’t reach the notebook.</h3><p>Please try again in a moment.</p></div>';}}
document.querySelectorAll('[data-filter]').forEach(b=>b.addEventListener('click',()=>{filter=b.dataset.filter;limit=12;render();}));
$('#search').addEventListener('input',()=>{limit=12;render();});$('#sort').addEventListener('change',render);
$('#contributors').addEventListener('click',e=>{const b=e.target.closest('[data-person]');if(b){person=b.dataset.person;limit=12;render();}});
$('#everyone').addEventListener('click',()=>{person='';limit=12;render();});
$('#load-more').addEventListener('click',()=>{limit+=12;render();});
$('#posts').addEventListener('click',e=>{const b=e.target.closest('[data-read]');if(b){const id=b.dataset.read;remember(id,!read.has(id));render();return;}const a=e.target.closest('[data-open]');if(a){remember(a.dataset.open,true);setTimeout(render,50);}});
$('#posts').addEventListener('auxclick',e=>{const a=e.target.closest('[data-open]');if(a&&e.button===1){remember(a.dataset.open,true);setTimeout(render,50);}});
$('#semester').addEventListener('change',()=>{courseID=$('#semester').value;person='';limit=12;data=null;history.replaceState(null,'','?course='+encodeURIComponent(courseID));load();});
window.addEventListener('storage',e=>{if(e.key==='course-voices:read:v1'){loadRead();render();}});
setInterval(()=>{if(!document.hidden)load();},15*60*1000);
load();
