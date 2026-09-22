// These inner edges are shared by the original mark and its reveal aperture.
// Only the two short mouth caps close the aperture; the exterior is never a window.
const leftInner='C 542 251 474 316 359 324 C 257 331 217 369 169 425 C 130 471 125 521 126 552 Q 127 567 112 567';
const rightInner='C 166 512 175 468 215 428 C 258 384 300 369 369 364 C 482 356 558 291 572 168 Q 574 151 583 151';

export const logoLeft=`M 139 20 L 427 20 C 502 20 542 65 542 130 ${leftInner} L 87 567 C 38 567 20 532 20 489 L 20 145 C 20 78 65 20 139 20 Z`;
export const logoRight=`M 583 151 L 608 151 C 674 151 710 192 710 256 L 710 564 C 710 632 666 670 602 670 L 268 670 C 202 670 166 636 166 575 ${rightInner} Z`;
export const logoAperture=`M 542 130 ${leftInner} L 166 575 ${rightInner} Z`;
export const logoFocus={x:365,y:344};
