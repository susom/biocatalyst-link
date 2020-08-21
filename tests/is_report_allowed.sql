select do_permit_this_report  from 
                (select rr.report_id
                    ,rr.title
                    ,restricted.are_reports_restricted
                    ,JSON_CONTAINS(bcreports.allowed_reports,CONCAT('\"',cast(rr.report_id as char(10)),'\"'),'$') as is_report_allowed
                    ,case 
                        when restricted.are_reports_restricted='no' then '1' 
                        when restricted.are_reports_restricted='yes' then JSON_CONTAINS(bcreports.allowed_reports,CONCAT('\"',cast(rr.report_id as char(10)),'\"'),'$')  
                        else 1 
                    END as do_permit_this_report
                        from redcap_external_modules rem
                        left join redcap_external_module_settings rems on rem.external_module_id = rems.external_module_id
                        left join redcap_reports rr on rems.project_id = rr.project_id
                        LEFT JOIN (select external_module_id,project_id,value as allowed_reports from redcap_external_module_settings where `key`='allowed_reports' and project_id=16) as bcreports 
                            ON rems.project_id=bcreports.project_id and rems.external_module_id=bcreports.external_module_id
                        LEFT JOIN (select external_module_id,project_id,value as are_reports_restricted from redcap_external_module_settings where `key`='are_reports_restricted' and project_id=16) as restricted 
                            ON rems.project_id=bcreports.project_id and rems.external_module_id=bcreports.external_module_id
                        where rem.directory_prefix = 'biocatalyst_link'
                        and rems.key = 'biocatalyst-enabled'
                        and rems.value = 'true'
                        and rr.project_id = 16
                        and rr.report_id = 6
                    ) as report_list