import {mkdir,writeFile} from 'node:fs/promises';
await mkdir('tests/fixtures',{recursive:true});
const rate=16000,duration=6;
const records=[];
for(const [i,frequency]of [220,330].entries()){
 const samples=rate*duration;const bytes=Buffer.alloc(44+samples*2);bytes.write('RIFF');bytes.writeUInt32LE(bytes.length-8,4);bytes.write('WAVEfmt ',8);bytes.writeUInt32LE(16,16);bytes.writeUInt16LE(1,20);bytes.writeUInt16LE(1,22);bytes.writeUInt32LE(rate,24);bytes.writeUInt32LE(rate*2,28);bytes.writeUInt16LE(2,32);bytes.writeUInt16LE(16,34);bytes.write('data',36);bytes.writeUInt32LE(samples*2,40);
 const pcm=[];for(let n=0;n<samples;n++){const envelope=Math.min(1,n/800,(samples-n)/800)*(.5+.5*Math.sin(n/rate*2));const sample=Math.round(Math.sin(2*Math.PI*frequency*n/rate)*envelope*800);pcm.push(sample/32768);bytes.writeInt16LE(sample,44+n*2);}
 const peaks=Array.from({length:100},(_,j)=>Math.max(...pcm.slice(j*samples/100,(j+1)*samples/100).map(Math.abs)));
 const id=`synthetic-tone-${i+1}`;await writeFile(`tests/fixtures/${id}.wav`,bytes);records.push({id,title:`TEST AUDIO ${i+1} — synthetic tone, not Sebastian’s work`,url:`/__fixtures/${id}.wav`,duration,peaks});
}
await writeFile('tests/fixtures/audio.json',JSON.stringify(records,null,2));console.log('Two low-amplitude synthetic PCM fixtures and measured peaks generated outside public.');
