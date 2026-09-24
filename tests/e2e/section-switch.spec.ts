import { mkdir } from 'node:fs/promises';
import { test, expect, type Page } from '@playwright/test';
import { ready, noOverflow } from './helpers';

type SwitchSide = 'left' | 'right';

async function switchMetrics(page: Page) {
  return page.evaluate(() => {
    const sectionSwitch = document.querySelector<HTMLElement>('.section-switch')!;
    const grid = document.querySelector<HTMLElement>('.project-grid')!;
    const social = document.querySelector<HTMLElement>('.social-rail')!;
    const shape = sectionSwitch.querySelector<SVGElement>('.section-switch-shape')!;
    const path = shape.querySelector<SVGPathElement>('.section-switch-body')!;
    const copy = sectionSwitch.querySelector<HTMLElement>(
      '.section-switch-copy',
    )!;
    const keys = sectionSwitch.querySelector<SVGGElement>(
      '.section-switch-keys',
    )!;
    const title = copy.querySelector<HTMLElement>('strong')!;
    const caption = copy.querySelector<HTMLElement>(':scope > span')!;
    const switchBox = sectionSwitch.getBoundingClientRect();
    const gridBox = grid.getBoundingClientRect();
    const socialBox = social.getBoundingClientRect();
    const visibleLeft = Math.max(0, switchBox.left);
    const visibleRight = Math.min(innerWidth, switchBox.right);
    const titleBox = title.getBoundingClientRect();
    const captionBox = caption.getBoundingClientRect();
    const pathMatrix = path.getScreenCTM()!.inverse();
    const isVisiblyPainted = (rect: SVGRectElement) => {
      const box = rect.getBoundingClientRect();
      const left = Math.max(0, box.left);
      const right = Math.min(innerWidth, box.right);
      const top = Math.max(0, box.top);
      const bottom = Math.min(innerHeight, box.bottom);
      if (right - left < 0.5 || bottom - top < 0.5) return false;
      const xs = [left + 0.25, (left + right) / 2, right - 0.25];
      const y = (top + bottom) / 2;
      return xs.some((x) =>
        path.isPointInFill(new DOMPoint(x, y).matrixTransform(pathMatrix)),
      );
    };
    const whiteKeys = [
      ...sectionSwitch.querySelectorAll<SVGRectElement>(
        '.section-switch-white-key',
      ),
    ];
    const blackKeys = [
      ...sectionSwitch.querySelectorAll<SVGRectElement>(
        '.section-switch-black-key',
      ),
    ];

    return {
      switchBox: {
        left: switchBox.left,
        right: switchBox.right,
        top: switchBox.top,
        bottom: switchBox.bottom,
        width: switchBox.width,
        height: switchBox.height,
      },
      gridBox: {
        left: gridBox.left,
        right: gridBox.right,
        top: gridBox.top,
      },
      socialBox: {
        left: socialBox.left,
        right: socialBox.right,
      },
      visibleWidth: Math.max(0, visibleRight - visibleLeft),
      position: getComputedStyle(sectionSwitch).position,
      transform: getComputedStyle(sectionSwitch).transform,
      shapeDisplay: getComputedStyle(shape).display,
      pathPointerEvents: getComputedStyle(path).pointerEvents,
      url: location.href,
      scrollY,
      title: document.querySelector('h1')?.textContent?.trim(),
      bodyCount: sectionSwitch.querySelectorAll('.section-switch-body').length,
      whiteKeyCount: whiteKeys.length,
      blackKeyCount: blackKeys.length,
      visibleWhiteKeyCount: whiteKeys.filter(isVisiblyPainted).length,
      visibleBlackKeyCount: blackKeys.filter(isVisiblyPainted).length,
      keyboardHeight: whiteKeys[0]?.getBBox().height,
      keyboardMinX: Math.min(...whiteKeys.map((key) => key.getBBox().x)),
      keyboardMaxX: Math.max(
        ...whiteKeys.map((key) => key.getBBox().x + key.getBBox().width),
      ),
      titleAxisX:
        (titleBox.left + titleBox.right) / 2 - switchBox.left,
      captionAxisX:
        (captionBox.left + captionBox.right) / 2 - switchBox.left,
      captionVisible: getComputedStyle(caption).display !== 'none',
      titleCenterY:
        (((titleBox.top + titleBox.bottom) / 2 - switchBox.top) /
          switchBox.height) *
        900,
      captionCenterY:
        (((captionBox.top + captionBox.bottom) / 2 - switchBox.top) /
          switchBox.height) *
        900,
      switchBackground: getComputedStyle(sectionSwitch).backgroundColor,
      shapeFill: getComputedStyle(path).fill,
      shapeStroke: getComputedStyle(path).stroke,
      labelColor: getComputedStyle(copy).color,
      keysOpacity: getComputedStyle(keys).opacity,
    };
  });
}

async function textFillCoverage(page: Page) {
  return page.evaluate(() => {
    const labels = [
      ...document.querySelectorAll<HTMLElement>(
        '.section-switch-copy > span, .section-switch-copy > strong',
      ),
    ].filter((label) => getComputedStyle(label).display !== 'none');
    const path = document.querySelector<SVGPathElement>(
      '.section-switch-body',
    )!;
    const matrix = path.getScreenCTM()!.inverse();
    const items = labels.map((label) => {
      const box = label.getBoundingClientRect();
      const edgeInset = Math.min(5, box.width / 3);
      const xs = [
        box.left + edgeInset,
        box.left + box.width / 2,
        box.right - edgeInset,
      ];
      const ys = Array.from(
        { length: 9 },
        (_, index) => box.top + 1 + ((box.height - 2) * index) / 8,
      );
      const misses = xs.flatMap((x) =>
        ys
          .filter((y) => {
            const localPoint = new DOMPoint(x, y).matrixTransform(matrix);
            return !path.isPointInFill(localPoint);
          })
          .map((y) => ({ x, y })),
      );
      return {
        text: label.textContent?.trim(),
        box: {
          left: box.left,
          right: box.right,
          top: box.top,
          bottom: box.bottom,
        },
        misses,
      };
    });

    return {
      items,
      viewport: { width: innerWidth, height: innerHeight },
    };
  });
}

async function expectTitleInsideShape(page: Page) {
  const coverage = await textFillCoverage(page);
  for (const item of coverage.items) {
    expect(item.box.left).toBeGreaterThanOrEqual(0);
    expect(item.box.right).toBeLessThanOrEqual(coverage.viewport.width);
    expect(item.box.top).toBeGreaterThanOrEqual(0);
    expect(item.box.bottom).toBeLessThanOrEqual(coverage.viewport.height);
    expect(item.misses, JSON.stringify(coverage)).toEqual([]);
  }
}

async function pathGeometry(page: Page) {
  return page.locator('.section-switch-body').evaluate((path: SVGPathElement) => {
    const svg = path.ownerSVGElement!;
    const transform = path.transform.baseVal.consolidate()?.matrix;
    const samples = Array.from({ length: 11 }, (_, index) => {
      const point = path.getPointAtLength((path.getTotalLength() * index) / 10);
      return { x: point.x, y: point.y };
    });
    return {
      d: path.getAttribute('d'),
      transform: path.getAttribute('transform'),
      matrix: transform
        ? {
            a: transform.a,
            b: transform.b,
            c: transform.c,
            d: transform.d,
            e: transform.e,
            f: transform.f,
          }
        : null,
      viewBox: svg.getAttribute('viewBox'),
      samples,
    };
  });
}

async function takeSwitchShot(page: Page, name: string) {
  await mkdir('test-results/section-switch-review', { recursive: true });
  await page.evaluate(() => scrollTo({ top: 0, behavior: 'instant' }));
  await page.waitForTimeout(260);
  await page.screenshot({
    path: `test-results/section-switch-review/${name}.png`,
  });
  await page.locator('head').evaluate((head) => {
    const guide = document.createElement('style');
    guide.dataset.sectionSwitchAxisGuide = '';
    guide.textContent = `.section-switch::before {
      content: '';
      position: absolute;
      z-index: 2;
      top: 0;
      bottom: 0;
      left: var(--section-switch-label-x);
      width: 1px;
      background: #ff2d55;
      pointer-events: none;
    }`;
    head.append(guide);
  });
  await page.screenshot({
    path: `test-results/section-switch-review/${name}-axis-guide.png`,
  });
  await page
    .locator('style[data-section-switch-axis-guide]')
    .evaluate((guide) => guide.remove());
}

async function checkDesktopSize(
  page: Page,
  width: number,
  height: number,
  side: SwitchSide,
  expectedTravel = 8,
) {
  await page.setViewportSize({ width, height });
  await page.waitForTimeout(260);
  const sectionSwitch = page.locator('.section-switch');
  const copy = sectionSwitch.locator('.section-switch-copy strong');
  const before = await switchMetrics(page);

  expect(before.position).toBe('fixed');
  expect(before.shapeDisplay).toBe('block');
  expect(before.pathPointerEvents).toBe('visiblepainted');
  expect(before.bodyCount).toBe(1);
  expect(before.whiteKeyCount).toBe(8);
  expect(before.blackKeyCount).toBe(6);
  expect(before.visibleWhiteKeyCount).toBe(6);
  expect(before.visibleBlackKeyCount).toBe(4);
  expect(before.keyboardHeight).toBe(40);
  expect(before.keyboardMinX).toBeLessThan(0);
  expect(before.keyboardMaxX).toBe(68);
  expect(before.titleAxisX).toBeCloseTo(side === 'right' ? 37 : 33, 1);
  expect(before.titleCenterY).toBeCloseTo(620, 1);
  if (before.captionVisible) {
    expect(before.captionAxisX).toBeCloseTo(before.titleAxisX, 1);
    expect(before.captionCenterY).toBeCloseTo(340, 1);
  }
  expect(before.switchBackground).toBe('rgba(0, 0, 0, 0)');
  expect(before.switchBox.width).toBeCloseTo(70, 0);
  expect(before.switchBox.height).toBeCloseTo(height - 16, 0);
  expect(before.visibleWidth).toBeCloseTo(54, 0);
  expect(before.shapeFill).toBe(
    side === 'right'
      ? 'rgba(237, 232, 220, 0.48)'
      : 'rgba(17, 17, 17, 0.48)',
  );
  expect(before.shapeStroke).toBe(
    side === 'right'
      ? 'rgba(237, 232, 220, 0.68)'
      : 'rgba(17, 17, 17, 0.68)',
  );
  expect(before.labelColor).toBe(
    side === 'right'
      ? 'rgba(24, 24, 24, 0.88)'
      : 'rgba(248, 244, 236, 0.88)',
  );
  expect(Number(before.keysOpacity)).toBeCloseTo(0.56, 2);
  await expectTitleInsideShape(page);

  await copy.hover();
  await page.waitForTimeout(260);
  const open = await switchMetrics(page);
  const travel =
    side === 'right'
      ? before.switchBox.left - open.switchBox.left
      : open.switchBox.left - before.switchBox.left;
  const contentGap =
    side === 'right'
      ? open.switchBox.left - open.gridBox.right
      : open.gridBox.left - open.switchBox.right;

  expect(travel).toBeCloseTo(expectedTravel, 0);
  expect(open.visibleWidth).toBeCloseTo(62, 0);
  expect(open.shapeFill).toBe(
    side === 'right'
      ? 'rgba(237, 232, 220, 0.96)'
      : 'rgba(17, 17, 17, 0.96)',
  );
  expect(open.shapeStroke).toBe(
    side === 'right' ? 'rgb(237, 232, 220)' : 'rgb(17, 17, 17)',
  );
  expect(open.labelColor).toBe(
    side === 'right' ? 'rgb(24, 24, 24)' : 'rgb(248, 244, 236)',
  );
  expect(Number(open.keysOpacity)).toBeCloseTo(0.78, 2);
  expect(open.visibleWhiteKeyCount).toBe(6);
  expect(open.visibleBlackKeyCount).toBe(5);
  expect(contentGap).toBeGreaterThanOrEqual(15.5);
  expect(open.socialBox.right).toBeLessThanOrEqual(
    side === 'right' ? open.switchBox.left - 15.5 : width,
  );
  expect(open.gridBox).toEqual(before.gridBox);
  expect(open.url).toBe(before.url);
  expect(open.scrollY).toBe(before.scrollY);
  expect(open.title).toBe(before.title);
  await expectTitleInsideShape(page);

  const transparentPoint = {
    x:
      side === 'right'
        ? Math.ceil(open.switchBox.left + 1)
        : Math.floor(open.switchBox.right - 1),
    y: Math.ceil(open.switchBox.top + open.switchBox.height * 0.4),
  };
  const transparentTarget = await page.evaluate(({ x, y }) => {
    const target = document.elementFromPoint(x, y);
    return {
      isSwitch: Boolean(target?.closest('.section-switch')),
      tag: target?.tagName,
    };
  }, transparentPoint);
  expect(transparentTarget.isSwitch, JSON.stringify(transparentTarget)).toBe(false);

  await page.mouse.move(width / 2, height / 2);
  await page.waitForTimeout(260);
  const closed = await switchMetrics(page);
  expect(closed.switchBox.left).toBeCloseTo(before.switchBox.left, 0);
  expect(closed.url).toBe(before.url);
  expect(closed.scrollY).toBe(before.scrollY);
}

test('desktop piano switch is fixed, shaped and moves without shifting content', async ({
  page,
}, info) => {
  test.skip(info.project.name !== 'desktop', 'desktop section switch geometry');
  test.setTimeout(90_000);

  await page.setViewportSize({ width: 1440, height: 900 });
  await ready(page, '/film-music/');
  const rightGeometry = await pathGeometry(page);
  expect(rightGeometry.transform).toBe('translate(70 0) scale(-1 1)');
  expect(rightGeometry.matrix).toEqual({
    a: -1,
    b: 0,
    c: 0,
    d: 1,
    e: 70,
    f: 0,
  });
  await expect
    .poll(() =>
      page.locator('html').evaluate((root) =>
        getComputedStyle(root)
          .getPropertyValue('--transition-duration')
          .trim(),
      ),
    )
    .toBe('800ms');
  await page.mouse.move(720, 450);
  await takeSwitchShot(page, 'film-music-rest-1440x900');
  await checkDesktopSize(page, 1440, 900, 'right');
  await page.locator('.section-switch-copy strong').hover();
  await takeSwitchShot(page, 'film-music-hover-1440x900');

  await page.mouse.move(720, 450);
  const fixedTop = (await switchMetrics(page)).switchBox.top;
  for (const selector of ['.project-grid', '.music-library', '.site-footer']) {
    await page.locator(selector).scrollIntoViewIfNeeded();
    expect((await switchMetrics(page)).switchBox.top).toBeCloseTo(fixedTop, 0);
  }
  await page.evaluate(() => scrollTo({ top: 0, behavior: 'instant' }));

  await page.getByRole('link', { name: 'Open Directing' }).click();
  await expect(page).toHaveURL(/\/directing\/$/);
  await expect(page.locator('html')).not.toHaveAttribute(
    'data-portfolio-transition',
  );
  await page.mouse.move(720, 450);
  const leftGeometry = await pathGeometry(page);
  expect(leftGeometry.transform).toBeNull();
  expect(leftGeometry.matrix).toBeNull();
  expect(rightGeometry.viewBox).toBe(leftGeometry.viewBox);
  expect(rightGeometry.d).toBe(leftGeometry.d);
  expect(rightGeometry.samples).toEqual(leftGeometry.samples);
  await takeSwitchShot(page, 'directing-rest-1440x900');
  await checkDesktopSize(page, 1440, 900, 'left');
  await page.locator('.section-switch-copy strong').hover();
  await takeSwitchShot(page, 'directing-hover-1440x900');

  await page.mouse.move(720, 450);
  await checkDesktopSize(page, 1920, 1080, 'left');
  await takeSwitchShot(page, 'directing-rest-1920x1080');
  await checkDesktopSize(page, 801, 900, 'left');
  await takeSwitchShot(page, 'directing-rest-801x900');

  await page.goto('/film-music/');
  await page.locator('html.js').waitFor();
  await page.evaluate(() => document.fonts.ready);
  await checkDesktopSize(page, 1920, 1080, 'right');
  await takeSwitchShot(page, 'film-music-rest-1920x1080');
  await checkDesktopSize(page, 801, 900, 'right');
  await takeSwitchShot(page, 'film-music-rest-801x900');
  await noOverflow(page);
});

test('keyboard focus opens the switch and reduced motion keeps it still', async ({
  page,
}, info) => {
  test.skip(info.project.name !== 'desktop', 'desktop keyboard behaviour');
  await page.setViewportSize({ width: 1440, height: 900 });
  await ready(page, '/film-music/');
  const sectionSwitch = page.getByRole('link', { name: 'Open Directing' });
  const resting = await switchMetrics(page);

  await page.locator('[data-project-card]').last().focus();
  await page.keyboard.press('Tab');
  await expect(sectionSwitch).toBeFocused();
  await page.waitForTimeout(260);
  const focused = await switchMetrics(page);
  expect(resting.switchBox.left - focused.switchBox.left).toBeCloseTo(8, 0);
  await expectTitleInsideShape(page);
  await expect(sectionSwitch.locator('.section-switch-body')).toHaveCSS(
    'stroke-width',
    '3px',
  );

  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.reload();
  await page.evaluate(() => document.fonts.ready);
  const reducedSwitch = page.getByRole('link', { name: 'Open Directing' });
  const reducedRest = await switchMetrics(page);
  await page.locator('[data-project-card]').last().focus();
  await page.keyboard.press('Tab');
  await expect(reducedSwitch).toBeFocused();
  await page.waitForTimeout(50);
  const reducedFocus = await switchMetrics(page);
  expect(reducedFocus.switchBox.left).toBeCloseTo(reducedRest.switchBox.left, 0);
  await expectTitleInsideShape(page);
  await expect(reducedSwitch.locator('.section-switch-body')).toHaveCSS(
    'stroke-width',
    '3px',
  );
});

test('mobile keeps the existing static section switch', async ({ page }, info) => {
  test.skip(info.project.name !== 'mobile', 'one mobile regression check');
  await ready(page, '/film-music/');
  const sectionSwitch = page.getByRole('link', { name: 'Open Directing' });
  await expect(sectionSwitch).toHaveCSS('position', 'static');
  await expect(sectionSwitch.locator('.section-switch-shape')).toHaveCSS(
    'display',
    'none',
  );
  await sectionSwitch.click();
  await expect(page).toHaveURL(/\/directing\/$/);
});
