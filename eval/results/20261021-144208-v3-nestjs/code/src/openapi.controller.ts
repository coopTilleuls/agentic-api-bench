import { Controller, Get, Res } from '@nestjs/common';
import type { Response } from 'express';
import { OPENAPI } from './openapi.js';

@Controller()
export class OpenApiController {
  @Get('openapi.json')
  document(@Res({ passthrough: true }) res: Response) {
    res.type('application/json');
    return OPENAPI;
  }
}
