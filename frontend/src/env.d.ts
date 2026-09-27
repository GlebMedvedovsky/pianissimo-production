/// <reference types="astro/client" />

declare namespace App {
 interface Locals {
  content?:import('../../contracts/content').Manifest;
  draftPreview?:{expiresAt:string;cmsUrl:string};
 }
}
