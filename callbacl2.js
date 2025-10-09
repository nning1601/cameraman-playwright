function inc(num,func){
    num+=1;
    func(num);
}

function showResult(value){
    console.log(value);
}

inc(100, showResult); // Output: 11