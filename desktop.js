(()=>{
 const links=document.querySelectorAll('.desktop-sticky-nav a');
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
