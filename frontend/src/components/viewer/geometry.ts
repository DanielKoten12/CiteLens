import type { Location } from "@/types/api";

export type PixelRect = {
  left: number;
  top: number;
  width: number;
  height: number;
};

export function locationToPixels(
  location: Location,
  pdfPageWidth: number,
  renderedCssWidth: number,
): PixelRect {
  const ratio = renderedCssWidth / pdfPageWidth;
  return {
    left: location.x * ratio,
    top: location.y * ratio,
    width: location.width * ratio,
    height: location.height * ratio,
  };
}
