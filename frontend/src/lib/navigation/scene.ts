import gsap from 'gsap';
import {ScrollTrigger} from 'gsap/ScrollTrigger';
import {siteConfig} from '../../config/site.config';

gsap.registerPlugin(ScrollTrigger);

// The real About is inside the sticky scene: target the end of its reveal.
export function scrollToSceneAbout(){
 const scene=document.querySelector<HTMLElement>('.hero-scene');
 const sticky=scene?.querySelector<HTMLElement>('.scene-sticky');
 if(!scene||!sticky||matchMedia('(prefers-reduced-motion: reduce)').matches)return false;
 window.scrollTo({top:scene.offsetTop+scene.offsetHeight-sticky.offsetHeight,behavior:'instant'});
 return true;
}

export function initScene(){
 const scene=document.querySelector<HTMLElement>('.hero-scene');
 if(!scene)return()=>{};
 const about=scene.querySelector<HTMLElement>('.about-section')!;
 const visuals=scene.querySelector<HTMLElement>('.scene-visuals')!;
 const logo=scene.querySelector<HTMLElement>('.scene-logo')!;
 const choices=scene.querySelectorAll<SVGAElement>('.logo-choice');
 const match=gsap.matchMedia();
 match.add({motion:'(prefers-reduced-motion: no-preference)',desktop:'(min-width:801px)'},context=>{
  if(!context.conditions?.motion)return;
  const select=gsap.utils.selector(scene);
  const revealStart=1.15;
  let previousPhase='';
  const syncPhase=()=>{
   const time=timeline.time();
   const phase=time>=revealStart?'about':time>=.95?'menu':'hero';
   if(phase===previousPhase)return;
   previousPhase=phase;
   scene.dataset.phase=phase;
   scene.classList.toggle('is-about-revealing',phase==='about');
   visuals.inert=phase==='about';
   about.inert=phase!=='about';
   choices.forEach(link=>link.setAttribute('tabindex',phase==='menu'?'0':'-1'));
   document.dispatchEvent(new Event('pianissimo:scene-change'));
  };
  const timeline=gsap.timeline({onUpdate:syncPhase,scrollTrigger:{
   trigger:scene,start:'top top',
   end:()=>`+=${Math.max(1,scene.offsetHeight-scene.querySelector<HTMLElement>('.scene-sticky')!.offsetHeight)}`,
   scrub:true,invalidateOnRefresh:true,
  }});
  // Native scroll: the spacer is exactly the animation's travel. Once revealed,
  // this same About continues in normal document flow, directly to the footer.
  timeline.to(select('.hero-heading,.hero-note,.scroll-cue,.hero-controls'),{autoAlpha:0,duration:.22},0)
   .to(select('.hero-backdrop'),{opacity:0,duration:.65},.12)
   .to(select('.split-backdrop'),{opacity:1,duration:.7},.1)
   .fromTo(logo,{scale:.14},{scale:1,duration:1,ease:'power1.inOut'},0)
   .to(select('.logo-label'),{opacity:1,duration:.3},.7)
   .to(select('.menu-about'),{autoAlpha:1,duration:.25},.75)
   .to(select('.logo-label,.menu-about'),{autoAlpha:0,duration:.15},revealStart)
   .fromTo(about,{autoAlpha:0,clipPath:'inset(0 48% 0 48%)'},
    {autoAlpha:1,clipPath:'inset(0 0% 0 0%)',duration:.65,ease:'power1.out'},revealStart)
   .to(select('.split-backdrop'),{opacity:0,duration:.35},revealStart)
   .to(logo,{scale:context.conditions.desktop?2.8:1.8,transformOrigin:'48% 51%',duration:1.1,ease:'power1.inOut'},revealStart)
   .to(select('.logo-half-black'),{y:()=>-(innerHeight*730/logo.offsetWidth+690),duration:1.1,ease:'power2.inOut'},revealStart)
   .to(select('.logo-half-cream'),{y:()=>innerHeight*730/logo.offsetWidth+690,duration:1.1,ease:'power2.inOut'},revealStart)
   .set(visuals,{visibility:'hidden'},revealStart+1.1);
  syncPhase();
  return()=>{
   timeline.kill();
   delete scene.dataset.phase;
   scene.classList.remove('is-about-revealing');
   visuals.inert=false;
   about.inert=false;
   choices.forEach(link=>link.removeAttribute('tabindex'));
   document.dispatchEvent(new Event('pianissimo:scene-change'));
  };
 });
 const listeners:Array<()=>void>=[];
 for(const [selector,slot] of [['.composer-choice','composerMenu'],['.director-choice','directingMenu']]){
  const link=scene.querySelector(selector!);
  const video=scene.querySelector<HTMLVideoElement>(`[data-slot-video=${slot}]`);
  if(!link||!video)continue;
  const start=()=>{if(scene.classList.contains('is-about-revealing'))return;video.muted=true;void video.play().catch(()=>{})};
  const stop=()=>video.pause();
  const pauseOnReveal=()=>{if(scene.classList.contains('is-about-revealing'))stop()};
  link.addEventListener('mouseenter',start);link.addEventListener('focus',start);
  link.addEventListener('mouseleave',stop);link.addEventListener('blur',stop);
  document.addEventListener('pianissimo:scene-change',pauseOnReveal);
  listeners.push(()=>{
   link.removeEventListener('mouseenter',start);link.removeEventListener('focus',start);
   link.removeEventListener('mouseleave',stop);link.removeEventListener('blur',stop);
   document.removeEventListener('pianissimo:scene-change',pauseOnReveal);stop();
  });
 }
 document.documentElement.style.setProperty('--transition-duration',`${siteConfig.motion.transitionDuration}ms`);
 return()=>{match.revert();listeners.forEach(fn=>fn());};
}
