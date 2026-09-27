import {validateManifest} from '../../../../contracts/content';
import {publicContent} from '../../../../contracts/public-content';

export function previewContent(input:unknown){
 const content=publicContent(validateManifest(input,false,'draft-preview'),'draft-preview');
 if(content.site.analytics)content.site.analytics.enabled=false;
 return content;
}
