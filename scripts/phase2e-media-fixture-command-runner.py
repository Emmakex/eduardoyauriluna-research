#!/usr/bin/env python3
"""Create exactly one fixed temporary Media fixture through the proven command gateway.

Acceptance-only: fixed command ID, fixed empty semantic payload, hard-coded public
1x1 PNG fixture. No user bytes, URL, path or arbitrary metadata enter through Git.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import importlib.util
import json
import os
import re
from pathlib import Path
from typing import Any

ROOT=Path(__file__).resolve().parents[1]
BASE=Path(__file__).with_name('phase2d-command-runner.py')
ACTION='media.acceptance.fixture.create'
COMMAND_ID='cmd-phase2e-media-fixture-0001'
PNG_BASE64='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4//8/AAX+Av4N70a4AAAAAElFTkSuQmCC'
PNG_BYTES=base64.b64decode(PNG_BASE64)
PNG_SHA256=hashlib.sha256(PNG_BYTES).hexdigest()
PLAN_RE=re.compile(r'^erm-media-ingest-plan-[a-f0-9]{20}-[a-f0-9]{8}$')
OP_RE=re.compile(r'^erm-media-ingest-op-[a-f0-9]{32}$')

spec=importlib.util.spec_from_file_location('phase2d_command_runner_media_fixture_cmd',BASE)
if spec is None or spec.loader is None: raise SystemExit('unable to load Phase 2D command runner')
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
CommandError=base.CommandError
write_result=base.write_result
sanitized_message=base.sanitized_message


def validate_command(command:dict[str,Any],registry:dict[str,Any]|None=None)->None:
    expected={
        'version':'1','command_id':COMMAND_ID,'tenant':'eduardo-research',
        'action':ACTION,'requested_by':'chatgpt','payload':{'acceptance_only':True},
    }
    if command!=expected: raise CommandError('Media fixture command must match the exact acceptance-only contract')


def load_validated_command(path:Path)->dict[str,Any]:
    command=base.load_json(path);validate_command(command);return command


def attachment_id(value:Any)->int|None:
    for obj in base.objects(value):
        v=obj.get('attachment_id')
        if isinstance(v,int) and not isinstance(v,bool) and v>0:return v
        if isinstance(v,str) and v.isdigit() and int(v)>0:return int(v)
    return None


def signal_true(value:Any,key:str)->bool:
    return any(obj.get(key) is True for obj in base.objects(value))


def execute(command_path:Path,result_dir:Path)->int:
    command=base.load_json(command_path);validate_command(command)
    existing=result_dir/f'{COMMAND_ID}.json'
    if existing.exists(): print(f'ALREADY_COMPLETED {existing}');return 0
    token=os.environ.get('PHASE2D_MCP_TOKEN','').strip()
    if not token: raise CommandError('PHASE2D_MCP_TOKEN is required')
    base_url=os.environ.get('PHASE2D_MCP_BASE_URL',base.DEFAULT_BASE_URL).strip().rstrip('/')
    client=base.McpClient(f'{base_url}/eduardo-research',token)
    plan_id=None;operation_id=None
    try:
        client.initialize()
        plan=client.call('site_media_ingest_plan',{
            'fileName':'phase2e-media-metadata-acceptance.png','mimeType':'image/png','contentBase64':PNG_BASE64,
            'title':'Phase 2E temporary Media acceptance fixture',
            'altText':'Temporary one-pixel Media acceptance fixture; not site content.',
            'caption':'Temporary reversible fixture for Media metadata gateway acceptance.',
            'description':'Acceptance-only attachment generated from fixed public test bytes and removed after verification.',
        })
        plan_id=base.regex_string(plan,PLAN_RE)
        applied=client.call('site_media_ingest_apply',{'planId':plan_id,'confirm':True})
        operation_id=base.regex_string(applied,OP_RE)
        verified=client.call('site_media_ingest_verify',{'operationId':operation_id})
        if not base.verified(verified) or not signal_true(verified,'checksum_matches') or not signal_true(verified,'managed_by_manager'):
            raise CommandError('Media fixture did not pass checksum/provenance verification')
        aid=attachment_id(applied) or attachment_id(verified)
        if aid is None: raise CommandError('Media fixture did not return attachment_id')
        result={
            'version':'1','status':'success','tenant':'eduardo-research','action':ACTION,'command_id':COMMAND_ID,'verified':True,
            'resource':{'attachment_id':aid,'file_name':'phase2e-media-metadata-acceptance.png','mime_type':'image/png','byte_size':len(PNG_BYTES),'sha256':PNG_SHA256,'temporary':True},
            'verification':{'checksum_matches':True,'managed_by_manager':True},
            'correlation':{'plan_id':plan_id,'operation_id':operation_id},
        }
        path=write_result(result_dir,COMMAND_ID,result);print(f'SUCCESS {path}');return 0
    except Exception as exc:
        if operation_id:
            try: client.call('site_media_ingest_rollback',{'operationId':operation_id,'confirm':True})
            except Exception: pass
        elif plan_id:
            try: client.call('site_media_ingest_cancel',{'planId':plan_id})
            except Exception: pass
        result={'version':'1','status':'failed','tenant':'eduardo-research','action':ACTION,'command_id':COMMAND_ID,'verified':False,'error':{'code':'fixture_failed','message':'Acceptance-only Media fixture creation failed.'},'correlation':{'plan_id':plan_id,'operation_id':operation_id}}
        path=write_result(result_dir,COMMAND_ID,result);print(f'FAILED {path}: {sanitized_message(str(exc),token)}',file=__import__('sys').stderr);return 1
    finally: client.close()


def self_test()->int:
    validate_command({'version':'1','command_id':COMMAND_ID,'tenant':'eduardo-research','action':ACTION,'requested_by':'chatgpt','payload':{'acceptance_only':True}})
    if len(PNG_BYTES)!=69 or PNG_SHA256!='e878950f8091ec010cf5cc723bdea027a8539cf7147cfea199c2f666232dcd4e': raise CommandError('fixed PNG fixture changed unexpectedly')
    print('phase2e-media-fixture-command-runner self-test: OK');return 0


def main()->int:
    p=argparse.ArgumentParser();p.add_argument('command',nargs='?',type=Path);p.add_argument('--result-dir',type=Path,default=ROOT/'commands'/'phase2d'/'results');p.add_argument('--self-test',action='store_true');args=p.parse_args()
    try:
        if args.self_test:return self_test()
        if args.command is None:p.error('command is required unless --self-test is used')
        return execute(args.command,args.result_dir)
    except CommandError as exc: print(f'ERROR: {exc}',file=__import__('sys').stderr);return 2

if __name__=='__main__': raise SystemExit(main())
