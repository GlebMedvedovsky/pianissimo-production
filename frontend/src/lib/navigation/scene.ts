import gsap from 'gsap';
import {ScrollTrigger} from 'gsap/ScrollTrigger';
import {siteConfig} from '../../config/site.config';
import {logoFocus} from './logo-geometry';

gsap.registerPlugin(ScrollTrigger);

let scrollFrame = 0;
export function scrollToPosition(top: number, smooth = true) {
 const reduced=matchMedia('(prefers-reduced-motion: reduce)').matches;
 cancelAnimationFrame(scrollFrame);
 if(!smooth||reduced){window.scrollTo({top,behavior:'instant'});return;}
 const from=scrollY;
 const distance=top-from;
 const started=performance.now();
 const duration=siteConfig.motion.transitionDuration;
 const step=(now:number)=>{
  const progress=Math.min(1,(now-started)/duration);
  const eased=progress<.5?4*progress**3:1-(-2*progress+2)**3/2;
  window.scrollTo({top:from+distance*eased,behavior:'instant'});
  if(progress<1)scrollFrame=requestAnimationFrame(step);
 };
 scrollFrame=requestAnimationFrame(step);
}

// The real About is inside the sticky scene: target the end of its reveal.
export function scrollToSceneAbout(smooth=false){
 const scene=document.querySelector<HTMLElement>('.hero-scene');
 const sticky=scene?.querySelector<HTMLElement>('.scene-sticky');
 if(!scene||!sticky)return false;
 scrollToPosition(scene.offsetTop+scene.offsetHeight-sticky.offsetHeight,smooth);
 return true;
}

export function initScene(){
 const scene=document.querySelector<HTMLElement>('.hero-scene');
 if(!scene)return()=>{};
 const about=scene.querySelector<HTMLElement>('.about-section')!;
 const visuals=scene.querySelector<HTMLElement>('.scene-visuals')!;
 const logo=scene.querySelector<HTMLElement>('.scene-logo')!;
 const mark=logo.querySelector<SVGSVGElement>('svg')!;
 const composition=mark.querySelector<SVGGElement>('.logo-composition')!;
 const aperture=scene.querySelector<SVGPathElement>('#scene-about-aperture path')!;
 const choices=scene.querySelectorAll<SVGAElement>('.logo-choice');
 const match=gsap.matchMedia();
 match.add({motion:'(prefers-reduced-motion: no-preference)'},context=>{
  if(!context.conditions?.motion)return;
  const select=gsap.utils.selector(scene);
  const revealStart=1.15;
  const logoTextRevealStart=.38;
  const logoTextRevealDuration=.25;
  const depthBlur=matchMedia('(max-width:600px)').matches?5:7;
  const reveal={progress:0};
  // A conservative inscribed circle guarantees the entire viewport (not just
  // its centre or sampled corners) fits inside this curved aperture at the end.
  const length=aperture.getTotalLength();
  let radius=Infinity;
  for(let distance=0;distance<=Math.ceil(length);distance++){
   const point=aperture.getPointAtLength(Math.min(distance,length));
   radius=Math.min(radius,Math.hypot(point.x-logoFocus.x,point.y-logoFocus.y));
  }
  radius=Math.max(1,radius-1); // Sampling step is <= 1 SVG unit.
  const renderReveal=()=>{
   const progress=reveal.progress;
   const complete=progress>=1;
   const edgeAlpha=Math.sin(Math.PI*progress)*.72;
   about.style.visibility=progress>0?'visible':'hidden';
   about.style.clipPath=complete?'none':'url(#scene-about-aperture)';
   about.style.setProperty('--about-reveal-progress',progress.toFixed(4));
   about.style.setProperty('--about-edge-alpha',edgeAlpha.toFixed(4));
   about.inert=!complete;
   visuals.style.visibility=complete?'hidden':'visible';
   // This CTM includes the responsive SVG viewBox and the menu's placement.
   // Translate it into About-local coordinates, then apply the very same zoom
   // to both halves and the aperture. About itself receives no transform.
   const base=mark.getScreenCTM();
   if(!base)return;
   const bounds=about.getBoundingClientRect();
   const focus=new DOMPoint(logoFocus.x,logoFocus.y).matrixTransform(base);
   const farthest=Math.max(...[[0,0],[innerWidth,0],[0,innerHeight],[innerWidth,innerHeight]]
    .map(([x,y])=>Math.hypot(x!-focus.x,y!-focus.y)));
   const endScale=Math.max(1,farthest/(radius*base.a))*1.02;
   const scale=endScale**progress;
   const zoom=new DOMMatrix().translate(logoFocus.x,logoFocus.y).scale(scale).translate(-logoFocus.x,-logoFocus.y);
   const mask=new DOMMatrix().translate(-bounds.left,-bounds.top).multiply(base).multiply(zoom);
   composition.setAttribute('transform',zoom.toString());
   aperture.setAttribute('transform',mask.toString());
  };
  let previousPhase='';
  const syncPhase=()=>{
   renderReveal();
   const time=timeline.time();
   const phase=time>revealStart?'about':time>=.95?'menu':'hero';
   if(phase===previousPhase)return;
   previousPhase=phase;
   scene.dataset.phase=phase;
   scene.classList.toggle('is-about-revealing',phase==='about');
   visuals.inert=phase==='about';
   choices.forEach(link=>link.setAttribute('tabindex',phase==='menu'?'0':'-1'));
   document.dispatchEvent(new Event('pianissimo:scene-change'));
  };
  const timeline=gsap.timeline({onUpdate:syncPhase,scrollTrigger:{
   trigger:scene,start:'top top',
   end:()=>`+=${Math.max(1,scene.offsetHeight-scene.querySelector<HTMLElement>('.scene-sticky')!.offsetHeight)}`,
   scrub:true,invalidateOnRefresh:true,onRefresh:renderReveal,
  }});
  // Native scroll: the spacer is exactly the animation's travel. Once revealed,
  // this same About continues in normal document flow, directly to the footer.
  timeline.fromTo(select('.hero-heading'),{autoAlpha:0},{autoAlpha:1,duration:.25,ease:'power1.out'},.58)
   .to(select('.hero-heading'),{autoAlpha:0,duration:.16,ease:'power1.in'},.88)
   .to(select('.hero-note,.scroll-cue,.hero-controls'),{autoAlpha:0,duration:.22},0)
   .to(select('.hero-backdrop'),{opacity:0,duration:.65},.12)
   .to(select('.split-backdrop'),{opacity:1,duration:.7},.1)
   .fromTo(logo,{scale:.14},{scale:1,duration:1,ease:'power1.inOut'},0)
   .fromTo(select('.logo-label'),{autoAlpha:0},{autoAlpha:1,duration:logoTextRevealDuration,ease:'power1.out'},logoTextRevealStart)
   .to(select('.menu-about'),{autoAlpha:1,duration:.25},.75)
   .to(select('.logo-label,.menu-about'),{autoAlpha:0,duration:.15},revealStart)
   .fromTo(about,{opacity:.08},{opacity:1,duration:.88,ease:'none'},revealStart)
   .fromTo(select('.about-portrait'),{autoAlpha:.12,scale:1.025},{autoAlpha:1,scale:1,duration:.92,ease:'power1.out'},revealStart+.04)
   .fromTo(select('.about-content'),{autoAlpha:0,y:24},{autoAlpha:1,y:0,duration:.86,ease:'power1.out'},revealStart+.18)
   .to(logo,{filter:`blur(${depthBlur}px)`,duration:1.1,ease:'power1.in'},revealStart)
   .to(reveal,{progress:1,duration:1.1,ease:'none'},revealStart);
  syncPhase();
  return()=>{
   timeline.kill();
   delete scene.dataset.phase;
   scene.classList.remove('is-about-revealing');
   visuals.inert=false;
   about.inert=false;
   about.style.removeProperty('visibility');
   about.style.removeProperty('clip-path');
   about.style.removeProperty('--about-reveal-progress');
   about.style.removeProperty('--about-edge-alpha');
   visuals.style.removeProperty('visibility');
   gsap.set([about,...select('.about-portrait,.about-content')],{clearProps:'opacity,visibility,transform'});
   gsap.set(logo,{clearProps:'filter'});
   composition.removeAttribute('transform');
   aperture.removeAttribute('transform');
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
 return()=>{cancelAnimationFrame(scrollFrame);match.revert();listeners.forEach(fn=>fn());};
}
