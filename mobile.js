(()=>{
 const menu=document.querySelector('.phone-menu');
 const nav=menu.querySelector('nav');
 const toggle=menu.querySelector('button');
 const dismiss=menu.querySelector('.phone-dismiss');
 const mobile=matchMedia('(max-width:780px)');

 function setOpen(open){
   menu.toggleAttribute('data-open',open);
   toggle.setAttribute('aria-expanded',String(open));
   toggle.setAttribute('aria-label',open?'Fermer le menu':'Ouvrir le menu');
   toggle.textContent=open?'×':'☰';
 }
 function close(){if(menu.hasAttribute('data-open'))setOpen(false);}
 function outside(event){
   if(!nav.contains(event.target) && !toggle.contains(event.target))close();
 }

 toggle.addEventListener('click',()=>setOpen(!menu.hasAttribute('data-open')));
 // Native fragment navigation follows after dismissal, including a repeated selection.
 nav.addEventListener('click',event=>{
   const link=event.target.closest('a[href^="#"]');
   if(!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)return;
   close();
 });
 dismiss.addEventListener('click',event=>{event.preventDefault();close();});
 const capture={capture:true,passive:true};
 for(const type of ['pointerdown','touchstart','touchmove','wheel','click']){
   document.addEventListener(type,outside,capture);
 }
 // Covers inertial scrolling and page movement initiated without a touch event.
 window.addEventListener('scroll',event=>{
   if(event.target===window || event.target===document || event.target===document.scrollingElement)close();
 },capture);
 document.addEventListener('keydown',event=>{
   if(event.key==='Escape' && menu.hasAttribute('data-open')){
     close();
     toggle.focus({preventScroll:true});
   }
 });
 window.addEventListener('hashchange',close);
 window.addEventListener('pagehide',close);
 mobile.addEventListener('change',close);
 menu.setAttribute('data-ready','');
 toggle.hidden=false;
})();
(()=>{
 const links=document.querySelectorAll('header.mobile-original .logo');
 links.forEach(link=>link.addEventListener('click',e=>{
  if(e.defaultPrevented||e.button!==0||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;
  const hash=link.getAttribute('href'); if(!hash||!hash.startsWith('#'))return;
  const target=document.getElementById(hash.slice(1)); if(!target)return;
  e.preventDefault();
  try{if(location.hash!==hash)history.pushState(null,'',hash);}catch(_){}
  const reduced=matchMedia('(prefers-reduced-motion: reduce)').matches;
  requestAnimationFrame(()=>{if(hash==='#accueil'||hash==='#accueil-mobile')window.scrollTo({top:0,behavior:reduced?'instant':'smooth'});else target.scrollIntoView({block:'start',behavior:reduced?'instant':'smooth'});});
 }));
})();
