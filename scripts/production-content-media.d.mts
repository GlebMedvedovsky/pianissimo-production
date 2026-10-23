import type { IncomingMessage, ServerResponse } from "node:http";

export function createProductionContentMediaMiddleware(
  mediaRoot: string,
): (
  request: IncomingMessage,
  response: ServerResponse,
  next: () => void,
) => void;
