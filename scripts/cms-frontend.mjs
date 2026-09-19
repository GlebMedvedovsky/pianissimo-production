import path from 'node:path';
import {createInterface} from 'node:readline/promises';
import {createWorkflow} from './lib/cms-frontend.mjs';
const root=path.resolve(import.meta.dirname,'..');
const [command,id,option,expectedFile]=process.argv.slice(2);
try {
  const flow=await createWorkflow(root);
  if(command==='review') {
    if(option && (option!=='--expected' || !expectedFile)) throw Error('Expected --expected <reviewed-diff.json>');
    console.log(JSON.stringify(flow.review(id,expectedFile?path.resolve(root,expectedFile):undefined),null,2));
  }
  else if(command==='apply' || command==='rollback') {
    const plan=command==='rollback'?flow.rollbackReview():JSON.parse((await import('node:fs')).readFileSync(path.join(flow.state,'plans',/^[a-f0-9]{64}$/.test(id)?id+'.json':'invalid'),'utf8'));
    const token=command==='rollback'?plan.planHash:id;
    console.log(JSON.stringify(plan,null,2));
    console.log('LOCAL FRONTEND 4321 (may be shared through Ports). Production remains blocked.');
    const rl=createInterface({input:process.stdin,output:process.stdout});
    const answer=await rl.question('Type the complete confirmation hash '+token+': '); rl.close();
    console.log(JSON.stringify(command==='apply'?flow.apply(id,answer.trim()):flow.rollback(answer.trim()),null,2));
  } else throw Error('Usage: node scripts/cms-frontend.mjs review <candidate-hash> [--expected <reviewed-diff.json>] | apply <plan-hash> | rollback');
}catch(error){console.error(error.message);process.exitCode=1;}
