import { Controller, Get } from '@nestjs/common';
import { OPENAPI_DOCUMENT } from './openapi.document.js';

@Controller('openapi.json')
export class OpenApiController {
  @Get()
  document(): object {
    return OPENAPI_DOCUMENT;
  }
}
