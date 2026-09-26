import { test, expect, type Page } from '@playwright/test';
import { ready, noOverflow } from './helpers';

async function desktopSwitch(page: Page, side: 'left' | 'right', checkFocus = false) {
  await page.mouse.move(
    await page.evaluate(() => innerWidth / 2),
    await page.evaluate(() => innerHeight / 2),
  );
  await page.waitForTimeout(240);
  const sectionSwitch = page.locator('.section-switch');
  const before = await sectionSwitch.evaluate((element) => {
    const box = element.getBoundingClientRect();
    const style = getComputedStyle(element);
    return {
      className: element.className,
      box: { left: box.left, right: box.right, width: box.width, height: box.height },
      position: style.position,
      background: style.backgroundColor,
      color: style.color,
      transform: style.transform,
      transition: getComputedStyle(document.documentElement)
        .getPropertyValue('--transition-duration')
        .trim(),
    };
  });
  expect(before.position).toBe('fixed');
  expect(before.className).toContain(side === 'left' ? 'switch-left' : 'section-switch');
  expect(before.box.width).toBeCloseTo(46, 0);
  expect(before.box.height).toBeCloseTo(await page.evaluate(() => innerHeight), 0);
  expect(before.transform).toBe('none');
  expect(before.transition).toBe('800ms');
  expect(await sectionSwitch.locator('svg').count()).toBe(0);
  expect(await sectionSwitch.locator('.section-switch-keys').count()).toBe(0);
  expect(before.background).toBe('rgb(181, 176, 167)');
  expect(before.color).toBe('rgb(20, 20, 20)');
  if (side === 'right') {
    expect(before.box.right).toBeCloseTo(await page.evaluate(() => innerWidth), 0);
  } else {
    expect(before.box.left).toBeCloseTo(0, 0);
  }

  const axis = await sectionSwitch.evaluate((element) => {
    const caption = element.querySelector('.section-switch-caption')!.getBoundingClientRect();
    const title = element.querySelector('.section-switch-title')!.getBoundingClientRect();
    const titleText = element.querySelector('.section-switch-title > span:first-child')!.getBoundingClientRect();
    const arrow = element.querySelector('.section-switch-arrow')!.getBoundingClientRect();
    const titleStyle = getComputedStyle(element.querySelector('.section-switch-title')!);
    const captionStyle = getComputedStyle(element.querySelector('.section-switch-caption')!);
    const copyStyle = getComputedStyle(element.querySelector('.section-switch-copy')!);
    return {
      caption: caption.left + caption.width / 2,
      title: title.left + title.width / 2,
      arrow: arrow.left + arrow.width / 2,
      order: {
        arrow: arrow.top + arrow.height / 2,
        title: titleText.top + titleText.height / 2,
        caption: caption.top + caption.height / 2,
      },
      orderGaps: {
        captionToTitle: title.top - caption.bottom,
        titleToArrow: arrow.top - titleText.bottom,
      },
      titleSize: parseFloat(titleStyle.fontSize),
      captionSize: parseFloat(captionStyle.fontSize),
      titleFamily: titleStyle.fontFamily,
      captionFamily: captionStyle.fontFamily,
      gap: parseFloat(copyStyle.gap),
      copyDirection: copyStyle.flexDirection,
      titleDirection: titleStyle.flexDirection,
    };
  });
  expect(Math.abs(axis.caption - axis.title)).toBeLessThanOrEqual(2);
  expect(Math.abs(axis.arrow - axis.title)).toBeLessThanOrEqual(2);
  expect(axis.titleSize).toBeGreaterThanOrEqual(28);
  expect(axis.titleSize).toBeGreaterThan(axis.captionSize * 3);
  expect(axis.captionSize).toBe(8);
  expect(axis.titleFamily).not.toBe(axis.captionFamily);
  expect(axis.copyDirection).toBe('row');
  expect(axis.titleDirection).toBe('row');
  expect(axis.order.caption).toBeLessThan(axis.order.title);
  expect(axis.order.title).toBeLessThan(axis.order.arrow);

  await sectionSwitch.hover();
  await expect.poll(() => sectionSwitch.evaluate(element => element.getBoundingClientRect().width)).toBeCloseTo(58, 0);
  await expect(sectionSwitch).toHaveCSS('background-color', side === 'right' ? 'rgb(243, 240, 234)' : 'rgb(20, 20, 20)');
  await expect(sectionSwitch).toHaveCSS('color', side === 'right' ? 'rgb(20, 20, 20)' : 'rgb(243, 240, 234)');
  if (checkFocus) {
    await page.locator('[data-project-card]').last().focus();
    await page.keyboard.press('Tab');
    await expect(sectionSwitch).toBeFocused();
    await expect(sectionSwitch).toHaveCSS('outline-style', 'solid');
  }
  await noOverflow(page);
  return axis;
}

test('desktop section switch is full-height, expands inward, mirrors exactly and keeps the approved slide timing', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop', 'desktop section switch');
  await page.setViewportSize({ width: 1440, height: 900 });
  await ready(page, '/film-music/');
  await page.mouse.move(720, 80);await page.waitForTimeout(240);
  await page.screenshot({ path: info.outputPath('film-music-switch-rest-1440.png') });
  const right = await desktopSwitch(page, 'right', true);
  await page.screenshot({ path: info.outputPath('film-music-switch-hover-1440.png') });
  await page.getByRole('link', { name: 'Open Directing' }).click();
  await expect(page).toHaveURL(/\/directing\/$/);
  await expect(page.locator('html')).not.toHaveAttribute('data-portfolio-transition');
  await page.mouse.move(720, 80);await page.waitForTimeout(240);
  await page.screenshot({ path: info.outputPath('directing-switch-rest-1440.png') });
  const left = await desktopSwitch(page, 'left');
  await page.screenshot({ path: info.outputPath('directing-switch-hover-1440.png') });
  expect(left.titleSize).toBe(right.titleSize);
  expect(left.captionSize).toBe(right.captionSize);
  expect(left.gap).toBe(right.gap);
  expect(left.orderGaps.captionToTitle).toBeCloseTo(right.orderGaps.captionToTitle, 0);
  expect(left.orderGaps.titleToArrow).toBeCloseTo(right.orderGaps.titleToArrow, 0);
  await page.setViewportSize({ width: 1024, height: 768 });
  await desktopSwitch(page, 'left');
  await page.screenshot({ path: info.outputPath('directing-switch-1024.png') });
});

test('mobile keeps the existing static section navigation without decorative geometry', async ({ page }, info) => {
  test.skip(info.project.name !== 'mobile', 'mobile section switch');
  await ready(page, '/film-music/');
  const sectionSwitch = page.getByRole('link', { name: 'Open Directing' });
  await expect(sectionSwitch).toHaveCSS('position', 'static');
  expect(await sectionSwitch.locator('svg').count()).toBe(0);
  await noOverflow(page);
  await sectionSwitch.click();
  await expect(page).toHaveURL(/\/directing\/$/);
});
