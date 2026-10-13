'use strict';
const fs=require('node:fs');
const path=require('node:path');
const playwright=require(process.env.STAFF_PLAYWRIGHT_MODULE||'playwright');
const origin=process.env.STAFF_TEST_ORIGIN||'https://staff.vve.local:8443';
const productionUrl=new URL(origin);productionUrl.port='443';
const productionOrigin=process.env.STAFF_PRODUCTION_ORIGIN||productionUrl.origin;
const testIp=process.env.STAFF_TEST_IP||'127.0.0.1';
const privateDir=process.env.STAFF_TEST_OUTPUT_DIR?path.resolve(process.env.STAFF_TEST_OUTPUT_DIR):path.resolve(__dirname,'../../private-runtime');
const accessFile=process.env.STAFF_TEST_ACCESS_FILE?path.resolve(process.env.STAFF_TEST_ACCESS_FILE):path.join(privateDir,'bootstrap-secrets.json');
const productionAccessFile=process.env.STAFF_PRODUCTION_ACCESS_FILE?path.resolve(process.env.STAFF_PRODUCTION_ACCESS_FILE):path.join(privateDir,'admin-credentials.json');
function readAccess(file=accessFile){return JSON.parse(fs.readFileSync(file,'utf8'))}
function launchOptions(url){const channel=process.env.STAFF_BROWSER_CHANNEL||(process.platform==='win32'?'msedge':'');return {headless:true,...(channel?{channel}:{}),...(url?{args:[`--host-resolver-rules=MAP ${new URL(url).hostname} ${testIp}`]}:{})}}
function safeError(error,access={}){let message=error?.stack||String(error);for(const value of Object.values(access))if(typeof value==='string'&&value.length>=4)message=message.split(value).join('[redacted]');return message}
module.exports={chromium:playwright.chromium,origin,productionOrigin,testIp,privateDir,accessFile,productionAccessFile,readAccess,launchOptions,safeError};
