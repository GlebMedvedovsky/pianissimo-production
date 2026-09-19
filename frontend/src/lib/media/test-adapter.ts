import type {MediaCoordinator} from './coordinator';
export function installTestAdapter(coordinator:MediaCoordinator){Object.assign(window,{__mediaTest:{coordinator,select:(id:string,queue:Parameters<MediaCoordinator['select']>[1])=>coordinator.select(queue.find(t=>t.id===id)!,queue)}})}
